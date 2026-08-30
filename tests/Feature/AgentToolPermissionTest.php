<?php

use App\Enums\AgentRole;
use App\Enums\AgentTool;
use App\Enums\IncidentStatus;
use App\Exceptions\UnauthorizedToolInvocationException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\Security\AgentToolAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('AgentToolAuthorizer enforces Least-Privilege Matrix rules per agent role', function () {
    $authorizer = app(AgentToolAuthorizer::class);

    // 1. Triage Agent: Read-only
    expect($authorizer->isAllowed(AgentRole::TRIAGE, AgentTool::GITHUB_GET_REPOSITORY))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::TRIAGE, AgentTool::VULN_SEARCH))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::TRIAGE, AgentTool::REPO_SEARCH_CODE))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::TRIAGE, AgentTool::SANDBOX_EXECUTE))->toBeFalse()
        ->and($authorizer->isAllowed(AgentRole::TRIAGE, AgentTool::REPO_MODIFY))->toBeFalse()
        ->and($authorizer->isAllowed(AgentRole::TRIAGE, AgentTool::GITHUB_CREATE_PULL_REQUEST))->toBeFalse();

    // 2. Reproduction Agent: Ephemeral sandbox only
    expect($authorizer->isAllowed(AgentRole::REPRODUCTION, AgentTool::SANDBOX_CREATE_ENVIRONMENT))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::REPRODUCTION, AgentTool::SANDBOX_EXECUTE))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::REPRODUCTION, AgentTool::REPO_MODIFY))->toBeFalse()
        ->and($authorizer->isAllowed(AgentRole::REPRODUCTION, AgentTool::GITHUB_CREATE_PULL_REQUEST))->toBeFalse();

    // 3. Patch Agent: Local modification only, no direct sandbox execution or PR creation
    expect($authorizer->isAllowed(AgentRole::PATCH, AgentTool::REPO_MODIFY))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::PATCH, AgentTool::REPO_READ_FILE))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::PATCH, AgentTool::SANDBOX_EXECUTE))->toBeFalse()
        ->and($authorizer->isAllowed(AgentRole::PATCH, AgentTool::GITHUB_CREATE_PULL_REQUEST))->toBeFalse();

    // 4. Validation Agent: Sandbox verification only
    expect($authorizer->isAllowed(AgentRole::VALIDATION, AgentTool::SANDBOX_EXECUTE))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::VALIDATION, AgentTool::SANDBOX_DESTROY_ENVIRONMENT))->toBeTrue()
        ->and($authorizer->isAllowed(AgentRole::VALIDATION, AgentTool::REPO_MODIFY))->toBeFalse()
        ->and($authorizer->isAllowed(AgentRole::VALIDATION, AgentTool::GITHUB_CREATE_PULL_REQUEST))->toBeFalse();

    // 5. Post-Approval: PR creation authorized
    expect($authorizer->isAllowed(AgentRole::POST_APPROVAL, AgentTool::GITHUB_CREATE_PULL_REQUEST))->toBeTrue();
});

test('AgentToolAuthorizer throws UnauthorizedToolInvocationException and escalates incident on security breach', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::TRIAGING]);
    $authorizer = app(AgentToolAuthorizer::class);

    try {
        // Triage agent attempting unauthorized sandbox execution
        $authorizer->authorize(AgentRole::TRIAGE, AgentTool::SANDBOX_EXECUTE, $incident);
        $this->fail('Expected UnauthorizedToolInvocationException was not thrown.');
    } catch (UnauthorizedToolInvocationException $e) {
        expect($e->role)->toBe('triage')
            ->and($e->tool)->toBe('sandbox.execute')
            ->and($e->getMessage())->toContain('Agent role [triage] is not authorized to invoke tool [sandbox.execute]');
    }

    $incident->refresh();

    // Incident escalated immediately
    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->transitions->last()->reason)->toContain('Security policy violation: unauthorized tool [sandbox.execute]');

    // Security audit log created
    expect(AuditLog::where('event', 'security.unauthorized_tool_invocation')->count())->toBe(1);
});

test('filterToolSchemasForRole dynamically strips unauthorized tools from LLM schema payload', function () {
    $authorizer = app(AgentToolAuthorizer::class);

    $allSchemas = [
        ['name' => AgentTool::GITHUB_GET_REPOSITORY->value, 'description' => 'Read repo'],
        ['name' => AgentTool::SANDBOX_EXECUTE->value, 'description' => 'Run command in container'],
        ['name' => AgentTool::REPO_MODIFY->value, 'description' => 'Write code diff'],
        ['name' => AgentTool::GITHUB_CREATE_PULL_REQUEST->value, 'description' => 'Open GitHub PR'],
    ];

    $triageSchemas = $authorizer->filterToolSchemasForRole(AgentRole::TRIAGE, $allSchemas);
    expect($triageSchemas)->toHaveCount(1)
        ->and($triageSchemas[0]['name'])->toBe(AgentTool::GITHUB_GET_REPOSITORY->value);

    $patchSchemas = $authorizer->filterToolSchemasForRole(AgentRole::PATCH, $allSchemas);
    expect($patchSchemas)->toHaveCount(2)
        ->and(array_column($patchSchemas, 'name'))->toEqualCanonicalizing([
            AgentTool::GITHUB_GET_REPOSITORY->value,
            AgentTool::REPO_MODIFY->value,
        ]);
});
