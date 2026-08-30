<?php

namespace App\Tools\Permissions;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Models\Incident;

class SandboxCapabilityPolicy
{
    /**
     * Determine if a capability or tool name matches the forbidden capability denylist.
     */
    public function isForbiddenCapability(string $capability): bool
    {
        $denylist = config('sandbox_permissions.forbidden_capabilities', [
            'host.execute',
            'host.filesystem',
            'docker.socket',
            'production.database',
            'production.shell',
            'system.exec',
            'system.process',
        ]);

        $normalized = strtolower(trim($capability));

        foreach ($denylist as $forbidden) {
            if ($normalized === strtolower($forbidden) || str_starts_with($normalized, strtolower($forbidden))) {
                return true;
            }
        }

        // Catch wildcard attempts to invoke host or system drivers
        if (str_starts_with($normalized, 'host.') || str_starts_with($normalized, 'system.') || str_starts_with($normalized, 'exec.')) {
            return true;
        }

        return false;
    }

    /**
     * Assert that a requested capability is not in the forbidden denylist.
     *
     * @throws ForbiddenHostCapabilityException
     */
    public function assertAllowedCapability(string $capability, Incident $incident): void
    {
        if ($this->isForbiddenCapability($capability)) {
            throw new ForbiddenHostCapabilityException(
                capability: $capability,
                reason: 'Direct host manipulation, socket access, or uncontained execution is strictly prohibited.',
                incident: $incident,
                violatingPayload: $capability,
            );
        }
    }

    /**
     * Inspect arguments for forbidden socket mounts or sensitive host filesystem paths.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ForbiddenHostCapabilityException
     */
    public function assertSafePaths(array $arguments, Incident $incident): void
    {
        $forbiddenPaths = config('sandbox_permissions.forbidden_path_patterns', [
            '/var/run/docker.sock',
            '/etc/shadow',
            '/etc/passwd',
            '/proc',
            '/sys',
            '/dev',
            '/root',
            '/.env',
        ]);

        foreach ($arguments as $key => $value) {
            if (is_string($value)) {
                foreach ($forbiddenPaths as $forbidden) {
                    if (str_contains($value, $forbidden)) {
                        throw new ForbiddenHostCapabilityException(
                            capability: 'host.filesystem',
                            reason: "Argument [{$key}] contains forbidden host path [{$forbidden}].",
                            incident: $incident,
                            violatingPayload: $value,
                        );
                    }
                }
            }
        }
    }
}
