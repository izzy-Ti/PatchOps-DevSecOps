<?php

namespace App\Services\MCP\Guards;

use App\Exceptions\MCP\UnauthorizedToolException;
use App\Tools\Enums\AgentRole;
use App\Tools\ToolRegistry;

class ToolPermissionGuard
{
    /**
     * Role-based tool permissions matrix mapping agent roles to authorized tool names.
     */
    private const PERMISSION_MATRIX = [
        'triage' => [
            'incident.fetch_details',
            'cve.lookup',
            'record_triage_result',
            'record_triage_analysis',
            'vulnerability.get_cve',
            'vulnerability.get_advisory',
            'vulnerability.search',
            'repository.inspect_structure',
            'repository.read_file',
            'repository.search_code',
            'repository.inspect_dependencies',
            'github.get_repository',
            'github.get_file',
            'github.get_dependency_manifest',
        ],
        'reproduction' => [
            'sandbox.create',
            'sandbox.create_environment',
            'sandbox.create_sandbox',
            'sandbox.clone',
            'sandbox.clone_repository',
            'sandbox.install',
            'sandbox.install_dependencies',
            'sandbox.execute',
            'sandbox.execute_command',
            'sandbox.logs',
            'sandbox.collect_logs',
            'sandbox.destroy',
            'sandbox.destroy_environment',
            'sandbox.destroy_sandbox',
            'record_reproduction_result',
            'record_reproduction_plan',
            'vulnerability.get_cve',
            'vulnerability.get_advisory',
            'repository.read_file',
            'repository.search_code',
            'repository.inspect_dependencies',
            'github.get_repository',
            'github.get_file',
        ],
        'patch' => [
            'sandbox.create',
            'sandbox.create_environment',
            'sandbox.create_sandbox',
            'sandbox.clone',
            'sandbox.clone_repository',
            'sandbox.install',
            'sandbox.install_dependencies',
            'sandbox.execute',
            'sandbox.execute_command',
            'sandbox.logs',
            'sandbox.collect_logs',
            'sandbox.destroy',
            'sandbox.destroy_environment',
            'sandbox.destroy_sandbox',
            'workspace.read_file',
            'workspace.write_patch',
            'record_patch_result',
            'repository.read_file',
            'repository.search_code',
            'repository.inspect_dependencies',
            'github.get_repository',
            'github.get_file',
            'github.create_pull_request',
        ],
        'validation' => [
            'sandbox.create',
            'sandbox.create_environment',
            'sandbox.create_sandbox',
            'sandbox.clone',
            'sandbox.clone_repository',
            'sandbox.install',
            'sandbox.install_dependencies',
            'sandbox.execute',
            'sandbox.execute_command',
            'sandbox.logs',
            'sandbox.collect_logs',
            'sandbox.destroy',
            'sandbox.destroy_environment',
            'sandbox.destroy_sandbox',
            'record_validation_result',
            'repository.read_file',
            'repository.search_code',
            'github.get_repository',
            'github.get_file',
        ],
        'post_approval' => [
            'github.get_repository',
            'github.create_pull_request',
        ],
    ];

    /**
     * Assert that the given agent role is authorized to invoke the specified tool.
     *
     * @throws UnauthorizedToolException
     */
    public static function assertPermission(AgentRole|\App\Enums\AgentRole|string $role, string $toolName, ?ToolRegistry $registry = null): void
    {
        $roleValue = $role instanceof \BackedEnum ? (string) $role->value : (string) $role;
        $enumRole = $role instanceof AgentRole ? $role : (AgentRole::tryFrom($roleValue) ?? AgentRole::TRIAGE);

        // Strict role restriction: Triage cannot invoke sandbox tools under any circumstance
        if ($roleValue === 'triage' && str_starts_with($toolName, 'sandbox.')) {
            throw new UnauthorizedToolException($enumRole, $toolName);
        }

        if (isset(self::PERMISSION_MATRIX[$roleValue])) {
            $allowedTools = self::PERMISSION_MATRIX[$roleValue];
            if (in_array($toolName, $allowedTools, true)) {
                return;
            }
        }

        // Dynamic registry check
        try {
            $registry ??= app(ToolRegistry::class);
            if ($registry && $registry->has($toolName) && $registry->authorize($toolName, $enumRole)) {
                return;
            }
        } catch (\Throwable) {
            // Ignore container resolution errors
        }

        throw new UnauthorizedToolException($enumRole, $toolName);
    }
}
