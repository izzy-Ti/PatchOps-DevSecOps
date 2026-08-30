<?php

namespace App\Services\Sandbox\Contracts;

use App\Models\Incident;
use App\Services\Sandbox\DTOs\SandboxExecutionResultDTO;

interface SandboxManagerInterface
{
    /**
     * Provision an ephemeral, hardened container environment for the incident.
     *
     * @param  array<string, string>  $envVars
     * @return string The generated unique workspace identifier
     */
    public function create(Incident $incident, string $ecosystem = 'node', array $envVars = []): string;

    /**
     * Execute a command inside the isolated container workspace.
     */
    public function execute(string $workspaceId, string $command, ?int $timeout = null): SandboxExecutionResultDTO;

    /**
     * Force-kill and clean up the container, disk volume, and process state.
     */
    public function destroy(string $workspaceId): bool;
}
