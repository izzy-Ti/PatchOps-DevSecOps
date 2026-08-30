<?php

namespace App\Services\MCP\Guards;

use App\Exceptions\MCP\RepositoryAccessDeniedException;
use App\Models\Incident;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;

class RepositoryAccessGuard
{
    /**
     * Validate that the requested tool call operates strictly within the incident's authorized repository boundary.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws RepositoryAccessDeniedException
     */
    public function validate(Incident $incident, array $arguments, string $toolName): void
    {
        $repoKeys = ['repository', 'repo', 'target_repo', 'owner_repo'];
        $requestedRepo = null;

        foreach ($repoKeys as $key) {
            if (! empty($arguments[$key])) {
                $requestedRepo = trim((string) $arguments[$key]);
                break;
            }
        }

        if ($requestedRepo === null) {
            return;
        }

        $authorizedRepo = $incident->getRepository();

        if (empty($authorizedRepo)) {
            return;
        }

        $normalizedRequested = strtolower($requestedRepo);
        $normalizedAuthorized = strtolower($authorizedRepo);

        if ($normalizedRequested !== $normalizedAuthorized) {
            AuditLogger::logSystemAction(
                event: 'security.cross_repository_access_denied',
                auditable: $incident,
                payload: [
                    'incident_id' => $incident->id,
                    'incident_number' => $incident->incident_number,
                    'tool' => $toolName,
                    'requested_repo' => $requestedRepo,
                    'authorized_repo' => $authorizedRepo,
                    'arguments' => $arguments,
                ],
                correlationId: $incident->correlation_id,
            );

            Log::critical("Cross-repository access violation detected on incident [{$incident->incident_number}].", [
                'tool' => $toolName,
                'requested' => $requestedRepo,
                'authorized' => $authorizedRepo,
            ]);

            throw new RepositoryAccessDeniedException(
                requestedRepo: $requestedRepo,
                authorizedRepo: $authorizedRepo,
                toolName: $toolName,
                incident: $incident,
            );
        }
    }
}
