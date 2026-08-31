<?php

namespace App\Services\Sandbox\Guards;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Models\Incident;
use Illuminate\Support\Facades\Log;

class SandboxSecurityAuditGuard
{
    /**
     * Forbidden container bind patterns that could lead to container escapes.
     *
     * @var array<int, string>
     */
    protected const FORBIDDEN_BIND_PATTERNS = [
        'docker.sock',
        '/var/run/docker.sock',
        '/run/docker.sock',
        '/run/containerd',
        '/var/run/containerd',
        'containerd.sock',
        '/var/run/crio',
        '/var/run/podman',
        '/proc',
        '/sys',
        '/dev/kmem',
        '/dev/mem',
    ];

    /**
     * Validate container configuration and execution arguments against anti-escape policies.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ForbiddenHostCapabilityException
     */
    public function validate(Incident $incident, array $arguments, string $toolName): void
    {
        if (! str_starts_with($toolName, 'sandbox.')) {
            return;
        }

        // 1. Docker Socket & Host Mount Inspection
        $binds = (array) ($arguments['binds'] ?? $arguments['volumes'] ?? []);
        foreach ($binds as $bind) {
            $normalized = strtolower(trim((string) $bind));
            foreach (self::FORBIDDEN_BIND_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    Log::critical("SECURITY ALERT: Agent attempted forbidden host socket mount [{$bind}] on incident [{$incident->incident_number}].");

                    throw new ForbiddenHostCapabilityException(
                        capability: 'docker_socket_mount',
                        reason: "CRITICAL ESCAPE BLOCKED: Mounting host socket or control path [{$bind}] is strictly forbidden.",
                        incident: $incident,
                        violatingPayload: $bind
                    );
                }
            }

            if (str_starts_with($normalized, '/etc:') || str_starts_with($normalized, '/root:') || $normalized === '/:/workspace') {
                Log::critical("SECURITY ALERT: Agent attempted root filesystem mount [{$bind}] on incident [{$incident->incident_number}].");

                throw new ForbiddenHostCapabilityException(
                    capability: 'host_root_mount',
                    reason: "CRITICAL ESCAPE BLOCKED: Mounting host root directory [{$bind}] is strictly forbidden.",
                    incident: $incident,
                    violatingPayload: $bind
                );
            }
        }

        // 2. Network Mode Inspection
        $networkMode = strtolower((string) ($arguments['network_mode'] ?? 'none'));
        if (in_array($networkMode, ['host', 'container'], true)) {
            Log::critical("SECURITY ALERT: Agent attempted host network attachment [{$networkMode}] on incident [{$incident->incident_number}].");

            throw new ForbiddenHostCapabilityException(
                capability: 'host_network_mode',
                reason: "CRITICAL ISOLATION BREACH: Attaching sandbox container to host network (network_mode={$networkMode}) is forbidden. Sandbox containers must run air-gapped (--network=none).",
                incident: $incident,
                violatingPayload: $networkMode
            );
        }

        // 3. Privileged Mode Inspection
        if (! empty($arguments['privileged']) && $arguments['privileged'] === true) {
            Log::critical("SECURITY ALERT: Agent attempted privileged container launch on incident [{$incident->incident_number}].");

            throw new ForbiddenHostCapabilityException(
                capability: 'privileged_container_execution',
                reason: 'CRITICAL ESCAPE BLOCKED: Containers cannot execute with privileged=true.',
                incident: $incident,
                violatingPayload: 'privileged=true'
            );
        }
    }
}
