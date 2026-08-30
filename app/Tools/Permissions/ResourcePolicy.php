<?php

namespace App\Tools\Permissions;

use App\Exceptions\MCP\ResourceAccessDeniedException;
use App\Models\Incident;

class ResourcePolicy
{
    /**
     * Forbidden sensitive file patterns that agents are categorically denied from accessing.
     */
    protected const FORBIDDEN_FILE_PATTERNS = [
        '/^\.env(\..+)?$/i',
        '/\.git\/config/i',
        '/id_rsa/i',
        '/id_ed25519/i',
        '/\/etc\/(passwd|shadow|hosts)/i',
        '/secrets\.(json|ya?ml|env)/i',
        '/credentials\.(json|ya?ml)/i',
    ];

    /**
     * Validate tool arguments against the active incident context using ABAC resource constraints.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ResourceAccessDeniedException
     */
    public function validate(ToolScope|string $scope, array $arguments, Incident $incident): void
    {
        $scopeEnum = $scope instanceof ToolScope ? $scope : (ToolScope::tryFrom($scope) ?? ToolScope::GITHUB_READ);

        // 1. Repository Scope Constraint
        if (! empty($arguments['repository'])) {
            $requestedRepo = trim($arguments['repository']);
            $allowedRepo = trim((string) $incident->repository);

            if (! empty($allowedRepo) && strcasecmp($requestedRepo, $allowedRepo) !== 0) {
                throw new ResourceAccessDeniedException(
                    scope: $scopeEnum,
                    violatingResource: $requestedRepo,
                    reason: "Repository [{$requestedRepo}] does not match authorized incident repository [{$allowedRepo}].",
                    incident: $incident,
                );
            }
        }

        // 2. Path Traversal & Forbidden File Scope Constraint
        $pathKeys = ['path', 'file', 'manifest_file', 'filename'];
        foreach ($pathKeys as $key) {
            if (! empty($arguments[$key])) {
                $path = (string) $arguments[$key];

                // Path Traversal Check
                if (str_contains($path, '..') || str_starts_with($path, '/etc/') || preg_match('/^[a-zA-Z]:\\\\windows/i', $path)) {
                    throw new ResourceAccessDeniedException(
                        scope: $scopeEnum,
                        violatingResource: $path,
                        reason: "Path [{$path}] violates security boundary: directory traversal and absolute system paths are forbidden.",
                        incident: $incident,
                    );
                }

                // Sensitive & Credential Files Check
                $basename = basename($path);
                foreach (self::FORBIDDEN_FILE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $path) || preg_match($pattern, $basename)) {
                        throw new ResourceAccessDeniedException(
                            scope: $scopeEnum,
                            violatingResource: $path,
                            reason: "File [{$path}] matches sensitive credential and infrastructure pattern: access is categorically denied.",
                            incident: $incident,
                        );
                    }
                }
            }
        }

        // 3. Sandbox Workspace Isolation Constraint
        if (! empty($arguments['workspace_id']) && in_array($scopeEnum, [ToolScope::SANDBOX_EXECUTE, ToolScope::SANDBOX_DESTROY], true)) {
            $workspaceId = (string) $arguments['workspace_id'];
            $allowedWorkspace = $incident->metadata['sandbox_workspace_id'] ?? null;
            $allowedPrefixes = [
                "sbx-{$incident->id}",
                "sbx-{$incident->incident_number}",
                'agent-sandbox',
            ];

            $isAuthorized = false;

            if ($allowedWorkspace && $workspaceId === $allowedWorkspace) {
                $isAuthorized = true;
            } else {
                foreach ($allowedPrefixes as $prefix) {
                    if (str_starts_with($workspaceId, $prefix)) {
                        $isAuthorized = true;
                        break;
                    }
                }
            }

            if (! $isAuthorized) {
                throw new ResourceAccessDeniedException(
                    scope: $scopeEnum,
                    violatingResource: $workspaceId,
                    reason: "Sandbox workspace [{$workspaceId}] is not bound to incident [{$incident->incident_number}].",
                    incident: $incident,
                );
            }
        }

        // 4. Branch & Mutating PR Constraint
        if ($scopeEnum === ToolScope::GITHUB_WRITE && ! empty($arguments['branch'])) {
            $branch = (string) $arguments['branch'];
            $defaultBranch = $incident->metadata['target_branch'] ?? 'main';

            if ($branch === $defaultBranch || $branch === 'master' || $branch === 'production') {
                throw new ResourceAccessDeniedException(
                    scope: $scopeEnum,
                    violatingResource: $branch,
                    reason: "Cannot create pull request targeting protected branch [{$branch}] directly as head branch.",
                    incident: $incident,
                );
            }
        }
    }
}
