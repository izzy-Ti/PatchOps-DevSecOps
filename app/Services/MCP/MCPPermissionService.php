<?php

namespace App\Services\MCP;

use App\Tools\Enums\AgentRole;
use App\Tools\ToolRegistry;

class MCPPermissionService
{
    public function __construct(
        protected ?ToolRegistry $registry = null,
    ) {
        $this->registry ??= app(ToolRegistry::class);
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
