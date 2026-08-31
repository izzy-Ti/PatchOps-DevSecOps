<?php

namespace App\Tools\MCP\Terminal;

use App\DTOs\ReproductionResultDTO;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Services\AuditLogger;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordReproductionResultTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: $this->name(),
            description: $this->description(),
            inputSchema: $this->parametersSchema(),
            requiredPermission: $this->requiredPermission(),
            allowedAgents: [
                AgentRole::REPRODUCTION,
            ],
            riskLevel: RiskLevel::LOW,
        );
    }

    public function name(): string
    {
        return 'record_reproduction_result';
    }

    public function description(): string
    {
        return 'Synthesize and record final structured proof-of-concept reproduction evidence and update incident workflow state.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reproduced' => [
                    'type' => 'boolean',
                    'description' => 'True if the vulnerability exploit succeeded, False if safe or not reproducible.',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Exact test or reproduction command executed (e.g. npm run test:security).',
                ],
                'exit_code' => [
                    'type' => 'integer',
                    'description' => 'Process exit code from test runner.',
                    'default' => 0,
                ],
                'stdout' => [
                    'type' => 'string',
                    'description' => 'Bounded standard output stream captured from sandbox.',
                ],
                'stderr' => [
                    'type' => 'string',
                    'description' => 'Bounded standard error stream captured from sandbox.',
                ],
                'duration_ms' => [
                    'type' => 'number',
                    'description' => 'Total execution runtime in milliseconds.',
                ],
                'environment' => [
                    'type' => 'object',
                    'description' => 'Runtime details (runtime, version, package manager).',
                ],
                'artifacts' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Generated PoC scripts, file diffs, or stack traces.',
                ],
                'observations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Structured semantic conclusions extracted during reproduction.',
                ],
            ],
            'required' => ['reproduced', 'command', 'observations'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::SANDBOX_EXECUTE;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $dto = ReproductionResultDTO::fromArray($arguments);

        // 1. Persist to relational incident_evidences table
        $evidence = IncidentEvidence::create([
            'incident_id' => $context->id,
            'stage' => 'reproduction',
            'reproduced' => $dto->reproduced,
            'command' => $dto->command,
            'exit_code' => $dto->exitCode,
            'stdout' => $dto->stdout,
            'stderr' => $dto->stderr,
            'duration_ms' => $dto->durationMs,
            'environment' => $dto->environment,
            'artifacts' => $dto->artifacts,
            'observations' => $dto->observations,
        ]);

        // 2. Persist to incident metadata for downstream PatchAgent handoff
        $context->metadata = array_merge($context->metadata ?? [], [
            'reproduction_result' => $dto->toArray(),
            'reproduction_evidence_id' => $evidence->id,
        ]);
        $context->save();

        // 3. State Machine Transition
        try {
            if ($dto->reproduced && ($context->status === IncidentStatus::REPRODUCING || $context->status === IncidentStatus::PRIORITIZED || $context->status === IncidentStatus::RECEIVED)) {
                if ($context->status !== IncidentStatus::REPRODUCING && $context->status->canTransitionTo(IncidentStatus::REPRODUCING)) {
                    $context->transitionTo(IncidentStatus::REPRODUCING, 'Reproduction in progress', 'agent', AgentRole::REPRODUCTION->value);
                }

                if ($context->status->canTransitionTo(IncidentStatus::REPRODUCED)) {
                    $context->transitionTo(
                        targetStatus: IncidentStatus::REPRODUCED,
                        reason: 'Vulnerability verified with deterministic reproduction evidence.',
                        actorType: 'agent',
                        actorId: AgentRole::REPRODUCTION->value,
                        metadata: ['evidence_id' => $evidence->id],
                    );
                }
            }
        } catch (Throwable $e) {
            Log::warning("Could not transition incident status for [{$context->incident_number}]: {$e->getMessage()}");
        }

        // 4. Audit Log
        AuditLogger::logSystemAction(
            event: 'reproduction.evidence_recorded',
            auditable: $context,
            payload: [
                'evidence_id' => $evidence->id,
                'reproduced' => $dto->reproduced,
                'command' => $dto->command,
                'duration_ms' => $dto->durationMs,
                'call_sites' => $dto->getVulnerableCallSites(),
            ],
            correlationId: $context->correlation_id,
        );

        return [
            'success' => true,
            'evidence_id' => $evidence->id,
            'reproduced' => $dto->reproduced,
            'call_sites' => $dto->getVulnerableCallSites(),
            'poc_script' => $dto->getPoCScript(),
            'data' => $dto->toArray(),
        ];
    }
}
