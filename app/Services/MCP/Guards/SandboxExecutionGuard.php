<?php

namespace App\Services\MCP\Guards;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Models\Incident;
use App\Services\AuditLogger;
use App\Tools\Permissions\SandboxCapabilityPolicy;
use Illuminate\Support\Facades\Log;

class SandboxExecutionGuard
{
    public function __construct(
        protected ?SandboxCapabilityPolicy $policy = null,
    ) {
        $this->policy ??= app(SandboxCapabilityPolicy::class);
    }

    /**
     * Inspect and sanitize a sandbox tool execution against breakout attempts and forbidden capabilities.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ForbiddenHostCapabilityException
     */
    public function validate(Incident $incident, array $arguments, string $toolName): void
    {
        // 1. Assert capability is not in the forbidden capability denylist
        $this->policy->assertAllowedCapability($toolName, $incident);

        // 2. Check for forbidden host paths in sandbox tool calls
        if (str_starts_with($toolName, 'sandbox.')) {
            $this->policy->assertSafePaths($arguments, $incident);
        }

        // 3. If executing inside sandbox, inspect command payload for breakout attempts and command injection
        if ($toolName === 'sandbox.execute' && ! empty($arguments['command'])) {
            $command = (string) $arguments['command'];
            $forbiddenCommandPatterns = config('sandbox_permissions.forbidden_command_patterns', [
                '/\b(docker|dockerd|podman|containerd|crictl)\b/i',
                '/\b(sudo|su|pkexec|doas)\b/i',
                '/\b(chroot|nsenter|unshare)\b/i',
                '/\bmount\b/i',
                '/\bumount\b/i',
                '/\/var\/run\/docker\.sock/i',
                '/(&&|\|\||;|\||`|\$\(|\$\{)/',
                '/\b(curl|wget|nc|netcat)\b/i',
                '/\b(chmod|chown)\b/i',
                '/rm\s+-rf\s+\//i',
                '/\b(sh|bash)\s+-c\b/i',
            ]);

            foreach ($forbiddenCommandPatterns as $pattern) {
                if (preg_match($pattern, $command)) {
                    AuditLogger::logSystemAction(
                        event: 'security.sandbox_breakout_attempt',
                        auditable: $incident,
                        payload: [
                            'incident_id' => $incident->id,
                            'incident_number' => $incident->incident_number,
                            'tool' => $toolName,
                            'command' => $command,
                            'matched_pattern' => $pattern,
                        ],
                        correlationId: $incident->correlation_id,
                    );

                    Log::critical("Sandbox breakout attempt detected on incident [{$incident->incident_number}]: {$command}");

                    throw new ForbiddenHostCapabilityException(
                        capability: 'sandbox.breakout_prevented',
                        reason: 'Command execution contains forbidden privileged escape or container manipulation pattern.',
                        incident: $incident,
                        violatingPayload: $command,
                    );
                }
            }
        }
    }
}
