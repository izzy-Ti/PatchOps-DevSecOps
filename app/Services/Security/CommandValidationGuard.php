<?php

namespace App\Services\Security;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Models\Incident;

class CommandValidationGuard
{
    /**
     * Allowed base executable binaries for sandbox execution.
     */
    protected const ALLOWLISTED_BINARIES = [
        'npm',
        'npx',
        'node',
        'pytest',
        'python',
        'python3',
        'phpunit',
        'pest',
        'vendor/bin/pest',
        'vendor/bin/phpunit',
        'vendor/bin/pint',
        'composer',
        'php',
        'git',
        'cargo',
        'go',
        'mvn',
        'gradle',
    ];

    /**
     * Operators that attempt command chaining or pipeline redirection.
     */
    protected const PROHIBITED_OPERATORS = [
        ';',
        '&&',
        '||',
        '|',
        '`',
        '$(',
        '>',
        '<',
        "\n",
        "\r",
    ];

    /**
     * Explicitly prohibited destructive or network exfiltration binaries.
     */
    protected const PROHIBITED_BINARIES = [
        'curl',
        'wget',
        'nc',
        'netcat',
        'bash',
        'sh',
        'zsh',
        'sudo',
        'chmod',
        'chown',
        'mkfifo',
        'socat',
        'rm',
    ];

    /**
     * Validate a command string before sandbox execution.
     *
     * @throws ForbiddenHostCapabilityException
     */
    public function validateCommand(string $command, ?Incident $incident = null): void
    {
        $trimmed = trim($command);
        if (empty($trimmed)) {
            return;
        }

        // 1. Assert no prohibited chaining or redirection operators
        foreach (self::PROHIBITED_OPERATORS as $operator) {
            if (str_contains($trimmed, $operator)) {
                throw new ForbiddenHostCapabilityException(
                    capability: 'command_chaining',
                    reason: "Command chaining or redirection operator [{$operator}] is strictly prohibited in sandbox execution.",
                    incident: $incident,
                    violatingPayload: $command,
                );
            }
        }

        // 2. Parse base binary from token vector
        $tokens = preg_split('/\s+/', $trimmed);
        $binary = $tokens[0] ?? '';

        // Normalize binary name (strip relative paths like ./vendor/bin/pest or /usr/bin/npm)
        $baseName = basename($binary);

        // 3. Prohibited binary check
        if (in_array(strtolower($baseName), self::PROHIBITED_BINARIES, true)) {
            throw new ForbiddenHostCapabilityException(
                capability: 'arbitrary_binary_execution',
                reason: "Binary [{$baseName}] is prohibited from execution.",
                incident: $incident,
                violatingPayload: $command,
            );
        }

        // 4. Assert binary is in allowlist
        $isAllowed = false;
        foreach (self::ALLOWLISTED_BINARIES as $allowed) {
            if ($binary === $allowed || $baseName === $allowed || str_ends_with($binary, '/'.$allowed)) {
                $isAllowed = true;
                break;
            }
        }

        if (! $isAllowed) {
            throw new ForbiddenHostCapabilityException(
                capability: 'unallowlisted_binary',
                reason: "Binary [{$binary}] is not in the allowlisted test runner or build tools list.",
                incident: $incident,
                violatingPayload: $command,
            );
        }
    }
}
