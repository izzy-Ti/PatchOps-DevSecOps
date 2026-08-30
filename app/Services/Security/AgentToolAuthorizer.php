<?php

namespace App\Services\Security;

use App\Enums\AgentRole;
use App\Enums\AgentTool;
use App\Enums\IncidentStatus;
use App\Exceptions\UnauthorizedToolInvocationException;
use App\Models\Incident;
use App\Services\AuditLogger;
use App\Services\Incident\IncidentStateMachine;
use Illuminate\Support\Facades\Log;

class AgentToolAuthorizer
{
    /**
     * Determine if a given agent role is permitted to invoke the specified tool.
     */
    public function isAllowed(AgentRole|string $role, AgentTool|string $tool): bool
    {
        $roleKey = $role instanceof AgentRole ? $role->value : $role;
        $toolKey = $tool instanceof AgentTool ? $tool->value : $tool;

        $matrix = config('agent_permissions.matrix', []);
        $allowedTools = $matrix[$roleKey] ?? [];

        return in_array($toolKey, $allowedTools, true);
    }

    /**
     * Authorize tool invocation, recording security audit violations and escalating incident on breach.
     *
     * @throws UnauthorizedToolInvocationException
     */
    public function authorize(AgentRole|string $role, AgentTool|string $tool, ?Incident $incident = null): void
    {
        $roleKey = $role instanceof AgentRole ? $role->value : $role;
        $toolKey = $tool instanceof AgentTool ? $tool->value : $tool;

        if ($this->isAllowed($roleKey, $toolKey)) {
            return;
        }

        Log::critical("Security violation: Agent role [{$roleKey}] attempted unauthorized tool invocation [{$toolKey}].", [
            'role' => $roleKey,
            'tool' => $toolKey,
            'incident_id' => $incident?->id,
            'incident_number' => $incident?->incident_number,
        ]);

        if ($incident) {
            AuditLogger::logSystemAction(
                event: 'security.unauthorized_tool_invocation',
                auditable: $incident,
                payload: [
                    'role' => $roleKey,
                    'tool' => $toolKey,
                    'incident_id' => $incident->id,
                    'incident_number' => $incident->incident_number,
                ],
                correlationId: $incident->correlation_id,
            );

            // Halt execution and escalate incident
            app(IncidentStateMachine::class)->transition(
                incident: $incident,
                targetStatus: IncidentStatus::ESCALATED,
                reason: "Security policy violation: unauthorized tool [{$toolKey}] invoked by role [{$roleKey}].",
                actorType: 'system',
                actorId: 'security-authorizer',
                metadata: [
                    'unauthorized_tool' => $toolKey,
                    'role' => $roleKey,
                ],
            );
        }

        throw new UnauthorizedToolInvocationException(
            role: $roleKey,
            tool: $toolKey,
            incident: $incident,
        );
    }

    /**
     * Get all permitted tools for an agent role.
     *
     * @return array<int, string>
     */
    public function getAllowedToolsForRole(AgentRole|string $role): array
    {
        $roleKey = $role instanceof AgentRole ? $role->value : $role;
        $matrix = config('agent_permissions.matrix', []);

        return $matrix[$roleKey] ?? [];
    }

    /**
     * Filter a list of tool schema definitions, retaining only tools permitted for the agent role.
     *
     * @param  array<int, array<string, mixed>>  $toolSchemas
     * @return array<int, array<string, mixed>>
     */
    public function filterToolSchemasForRole(AgentRole|string $role, array $toolSchemas): array
    {
        $allowedTools = $this->getAllowedToolsForRole($role);

        return array_values(array_filter($toolSchemas, function (array $schema) use ($allowedTools) {
            $toolName = $schema['name'] ?? '';

            return in_array($toolName, $allowedTools, true);
        }));
    }
}
