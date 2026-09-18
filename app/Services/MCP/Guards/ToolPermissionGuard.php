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
        'reviewer' => [
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
            'workspace.list_files',
            'repository.read_file',
            'repository.search_code',
            'repository.inspect_structure',
            'repository.inspect_dependencies',
            'github.get_repository',
            'github.get_file',
            'github.get_commit',
            'github.get_issue',
            'github.get_dependency_manifest',
            'vulnerability.get_cve',
            'vulnerability.get_advisory',
            'vulnerability.search',
            'record_review_verdict',
        ],
        'post_approval' => [
            'github.get_repository',
            'github.get_file',
            'github.create_branch',
            'github.apply_and_commit_patch',
            'github.create_pull_request',
        ],
        'orchestrator' => [
            'github.get_repository',
            'github.get_file',
            'github.create_branch',
            'github.apply_and_commit_patch',
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

        // Strict role restriction: Triage cannot invoke sandbox tools or GitHub mutation tools
        if ($roleValue === 'triage' && (str_starts_with($toolName, 'sandbox.') || str_starts_with($toolName, 'github.create_'))) {
            throw new UnauthorizedToolException($enumRole, $toolName);
        }

        // Strict role restriction: Reviewer cannot invoke write/mutation tools under any circumstance
        if ($roleValue === 'reviewer' && in_array($toolName, [
            'workspace.write_file',
            'workspace.write_patch',
            'repository.modify',
            'github.create_pull_request',
            'github.merge_pull_request',
            'github.create_branch',
            'github.apply_and_commit_patch',
            'github.update_issue',
        ], true)) {
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
            $registryInstance = $registry ?? app(ToolRegistry::class);
            if ($registryInstance && $registryInstance->has($toolName) && $registryInstance->authorize($toolName, $enumRole)) {
                return;
            }
        } catch (\Throwable) {
        }

        throw new UnauthorizedToolException($enumRole, $toolName);
    }
}
