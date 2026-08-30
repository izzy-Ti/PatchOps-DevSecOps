<?php

use App\Exceptions\MCP\ExecutionTimeoutExceededException;
use App\Exceptions\MCP\RepositoryAccessDeniedException;
use App\Exceptions\MCP\SandboxQuotaExceededException;
use App\Exceptions\MCP\ToolCallBudgetExceededException;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Models\ToolExecution;
use App\Services\MCP\DTOs\ToolErrorResponseDTO;
use App\Services\MCP\Guards\AgentExecutionBudgetGuard;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

test('ToolErrorResponseDTO formats standardized error envelopes across failure modes', function () {
    // 1. Permission Denied
    $permEx = new UnauthorizedToolException(AgentRole::TRIAGE, 'github.create_pull_request');
    $permDto = ToolErrorResponseDTO::fromException($permEx, ['target' => 'github']);
    $permArray = $permDto->toArray();

    expect($permArray['success'])->toBeFalse()
        ->and($permArray['error']['code'])->toBe(ToolErrorResponseDTO::PERMISSION_DENIED)
        ->and($permArray['error']['retryable'])->toBeFalse()
        ->and($permArray['error']['message'])->toContain('Agent role [triage] is unauthorized');

    // 2. Resource Out of Scope
    $repoEx = new RepositoryAccessDeniedException('other/repo', 'acme/target', 'github.get_file');
    $repoDto = ToolErrorResponseDTO::fromException($repoEx);
    $repoArray = $repoDto->toArray();

    expect($repoArray['success'])->toBeFalse()
        ->and($repoArray['error']['code'])->toBe(ToolErrorResponseDTO::RESOURCE_OUT_OF_SCOPE)
        ->and($repoArray['error']['retryable'])->toBeFalse();

    // 3. Tool Timeout
    $timeoutEx = new ProcessTimedOutException(
        new Process(['sleep', '100']),
        ProcessTimedOutException::TYPE_GENERAL
    );
    $timeoutDto = ToolErrorResponseDTO::fromException($timeoutEx);
    $timeoutArray = $timeoutDto->toArray();

    expect($timeoutArray['success'])->toBeFalse()
        ->and($timeoutArray['error']['code'])->toBe(ToolErrorResponseDTO::TOOL_TIMEOUT)
        ->and($timeoutArray['error']['retryable'])->toBeTrue();
});

test('AgentExecutionBudgetGuard blocks execution when tool call budget is exceeded', function () {
    config()->set('agent_budgets.triage.max_tool_calls', 2);

    $guard = app(AgentExecutionBudgetGuard::class);
    $incident = Incident::factory()->create();
    $agentRun = AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'triage',
        'status' => 'running',
    ]);

    // Create 2 pre-existing executions
    ToolExecution::create([
        'incident_id' => $incident->id,
        'agent_run_id' => $agentRun->id,
        'tool_name' => 'github.get_repository',
        'status' => 'success',
    ]);
    ToolExecution::create([
        'incident_id' => $incident->id,
        'agent_run_id' => $agentRun->id,
        'tool_name' => 'repository.inspect_dependencies',
        'status' => 'success',
    ]);

    $tool = app(ToolRegistry::class)->get('vulnerability.get_cve');

    // Attempting 3rd tool call -> Exceeds budget
    expect(fn () => $guard->validatePreExecution(AgentRole::TRIAGE, $tool, $incident, $agentRun->id))
        ->toThrow(ToolCallBudgetExceededException::class);
});

test('AgentExecutionBudgetGuard blocks execution when execution timeout is exceeded', function () {
    config()->set('agent_budgets.triage.max_execution_seconds', 60);

    $guard = app(AgentExecutionBudgetGuard::class);
    $incident = Incident::factory()->create();
    $agentRun = AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'triage',
        'status' => 'running',
        'started_at' => now()->subSeconds(65),
    ]);

    $tool = app(ToolRegistry::class)->get('vulnerability.get_cve');

    expect(fn () => $guard->validatePreExecution(AgentRole::TRIAGE, $tool, $incident, $agentRun->id))
        ->toThrow(ExecutionTimeoutExceededException::class);
});

test('AgentExecutionBudgetGuard blocks unauthorized sandbox creation', function () {
    $guard = app(AgentExecutionBudgetGuard::class);
    $incident = Incident::factory()->create();

    $sandboxTool = app(ToolRegistry::class)->get('sandbox.create_environment');

    // Triage role has max_sandboxes = 0 and allow_sandbox = false
    expect(fn () => $guard->validatePreExecution(AgentRole::TRIAGE, $sandboxTool, $incident))
        ->toThrow(SandboxQuotaExceededException::class);
});

test('AgentExecutionBudgetGuard truncates oversized tool responses', function () {
    config()->set('agent_budgets.triage.max_response_bytes', 500); // 500 bytes

    $guard = app(AgentExecutionBudgetGuard::class);

    $oversizedData = [
        'file_content' => str_repeat('A', 2000),
        'filename' => 'large.txt',
    ];

    $truncated = $guard->truncateOutput($oversizedData, AgentRole::TRIAGE);

    expect(strlen($truncated['file_content']))->toBeLessThan(2000)
        ->and($truncated['file_content'])->toContain('OUTPUT TRUNCATED BY MCP BUDGET GUARD');
});

test('MCPToolGateway invoke catches exceptions and returns standardized error envelope', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    // Triage agent attempts forbidden tool -> invoke() safely catches and formats
    $result = $gateway->invoke(
        role: AgentRole::TRIAGE,
        toolName: 'github.create_pull_request',
        arguments: [
            'repository' => 'acme/webapp',
            'branch' => 'patch/fix',
            'title' => 'Fix',
            'body' => 'Fix',
        ],
        context: $incident,
    );

    expect($result['success'])->toBeFalse()
        ->and($result['error']['code'])->toBe(ToolErrorResponseDTO::PERMISSION_DENIED)
        ->and($result['error']['retryable'])->toBeFalse()
        ->and($result['error']['details']['tool_name'])->toBe('github.create_pull_request');

    // Telemetry logged as denied
    $execution = ToolExecution::where('incident_id', $incident->id)->first();
    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('denied');
});
