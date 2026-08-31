<?php

use App\Agents\ReproductionAgent;
use App\DTOs\ReproductionResultDTO;
use App\Enums\IncidentStatus;
use App\Exceptions\Sandbox\SandboxInfrastructureException;
use App\Jobs\ExecuteReproductionJob;
use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Services\Orchestration\IncidentOrchestrator;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('MCPToolGateway automatically retries transient SandboxInfrastructureException and succeeds', function () {
    $registry = app(ToolRegistry::class);
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    $attempts = 0;
    $flakeyTool = new class($attempts) implements ToolInterface
    {
        public function __construct(public int &$attempts) {}

        public function definition(): ToolDefinition
        {
            return new ToolDefinition(
                name: 'sandbox.flakey_install',
                description: 'Flakey test install',
                inputSchema: ['type' => 'object'],
                requiredPermission: ToolPermission::SANDBOX_EXECUTE,
                allowedAgents: [AgentRole::REPRODUCTION],
            );
        }

        public function name(): string
        {
            return 'sandbox.flakey_install';
        }

        public function description(): string
        {
            return 'Flakey install';
        }

        public function parametersSchema(): array
        {
            return ['type' => 'object'];
        }

        public function requiredPermission(): ToolPermission
        {
            return ToolPermission::SANDBOX_EXECUTE;
        }

        public function execute(array $arguments, Incident $context): array
        {
            $this->attempts++;
            if ($this->attempts < 2) {
                throw new SandboxInfrastructureException('ETIMEDOUT: Registry connection dropped.');
            }

            return ['status' => 'installed', 'attempts' => $this->attempts];
        }
    };

    $registry->register($flakeyTool);

    $res = $gateway->execute(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.flakey_install',
        arguments: ['workspace_id' => "sbx-{$incident->id}"],
        context: $incident,
    );

    expect($res['success'])->toBeTrue()
        ->and($attempts)->toBe(2)
        ->and($res['data']['status'])->toBe('installed');
});

test('IncidentOrchestrator routes clean non-reproducible finding directly to TRIAGED_NOT_REPRODUCIBLE', function () {
    Queue::fake();

    $mockAgent = Mockery::mock(ReproductionAgent::class);
    $mockAgent->shouldReceive('execute')
        ->once()
        ->andReturn(new ReproductionResultDTO(
            reproduced: false,
            exitCode: 1,
            command: 'npm test',
            stdout: 'Tests passed cleanly. No vulnerability triggered.',
            stderr: '',
            durationMs: 150.0,
            environment: ['node' => '20'],
        ));

    $orchestrator = new IncidentOrchestrator($mockAgent);
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::PRIORITIZED,
        'repository' => 'acme/webapp',
    ]);

    $orchestrator->handlePrioritized($incident);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::TRIAGED_NOT_REPRODUCIBLE);
    Queue::assertNotPushed(ExecuteReproductionJob::class);
});

test('IncidentOrchestrator retries transient infrastructure failures and escalates to INFRA_FAILED after 3 attempts', function () {
    Queue::fake();

    $mockAgent = Mockery::mock(ReproductionAgent::class);
    $mockAgent->shouldReceive('execute')
        ->andThrow(new SandboxInfrastructureException('Docker daemon socket hang up.'));

    $orchestrator = new IncidentOrchestrator($mockAgent);
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::PRIORITIZED,
        'repository' => 'acme/webapp',
    ]);

    // Attempt 1: Retries -> PRIORITIZED
    $orchestrator->handlePrioritized($incident);
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PRIORITIZED);
    expect($incident->metadata['reproduction_retries'])->toBe(1);
    Queue::assertPushed(ExecuteReproductionJob::class, 1);

    // Attempt 2: Retries -> PRIORITIZED
    $orchestrator->handlePrioritized($incident);
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PRIORITIZED);
    expect($incident->metadata['reproduction_retries'])->toBe(2);
    Queue::assertPushed(ExecuteReproductionJob::class, 2);

    // Attempt 3: Exhausted -> INFRA_FAILED
    $orchestrator->handlePrioritized($incident);
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::INFRA_FAILED);
    expect($incident->metadata['reproduction_retries'])->toBe(3);
});
