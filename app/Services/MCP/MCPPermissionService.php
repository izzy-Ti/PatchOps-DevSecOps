<?php

namespace App\Services\MCP;

use App\Exceptions\MCP\ResourceAccessDeniedException;
use App\Models\Incident;
use App\Tools\Enums\AgentRole;
use App\Tools\Permissions\ResourcePolicy;
use App\Tools\Permissions\ToolScope;
use App\Tools\ToolRegistry;

class MCPPermissionService
{
    public function __construct(
        protected ?ToolRegistry $registry = null,
        protected ?ResourcePolicy $resourcePolicy = null,
    ) {
        $this->registry ??= app(ToolRegistry::class);
        $this->resourcePolicy ??= app(ResourcePolicy::class);
    }

    /**
     * Assert whether an agent role has capability permission to execute a specific tool.
     */
    public function isAllowed(AgentRole $role, string $toolName): bool
    {
        if (! $this->registry->has($toolName)) {
            return false;
        }

        return $this->registry->authorize($toolName, $role);
    }

    /**
     * Validate that tool arguments satisfy ABAC resource-level constraints against the active incident.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ResourceAccessDeniedException
     */
    public function validateResourceConstraints(ToolScope|string $scope, array $arguments, Incident $incident): void
    {
        $this->resourcePolicy->validate($scope, $arguments, $incident);
    }

    /**
     * Get all tool definitions authorized for an agent role.
     *
     * @return array<int, string>
     */
    public function getAvailableToolsForRole(AgentRole $role): array
    {
        $definitions = $this->registry->getToolsForRole($role);

        return array_map(fn ($def) => $def->name, $definitions);
    }
}
