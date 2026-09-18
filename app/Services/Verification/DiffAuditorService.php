<?php

namespace App\Services\Verification;

use App\DTOs\DiffAuditResultDTO;

class DiffAuditorService
{
    /**
     * Forbidden files whose modification constitutes an immediate rejection.
     */
    private const FORBIDDEN_FILE_PATTERNS = [
        '/\.github\//i' => 'GitHub workflows or actions configuration modified',
        '/Dockerfile/i' => 'Container Dockerfile modified',
        '/docker-compose/i' => 'Docker Compose orchestration file modified',
        '/\.env/i' => 'Environment configuration file modified',
        '/id_rsa/i' => 'Private key or identity credential file modified',
    ];

    /**
     * Forbidden code patterns in added lines (+).
     */
    private const FORBIDDEN_CODE_PATTERNS = [
        '/(dd|dump|var_dump)\s*\(/i' => 'PHP debug statement leaked',
        '/console\.(log|debug)\s*\(/i' => 'JavaScript console debug leaked',
        '/print\(.*debug.*\)/i' => 'Python debug print leaked',
        '/debugger;/i' => 'JavaScript debugger breakpoint leaked',
        '/(ghp_[a-zA-Z0-9_]{36}|AKIA[0-9A-Z]{16})/i' => 'Potential credential or API key leaked',
        '/-----BEGIN (RSA|OPENSSH|EC|DSA|PRIVATE KEY)-----/i' => 'Cryptographic private key header leaked',
        '/(\bverify_ssl\s*=\s*False\b|\bNODE_TLS_REJECT_UNAUTHORIZED\s*=\s*0\b)/i' => 'SSL/TLS verification disabled',
        '/chmod\s+777/i' => 'Insecure permissive file permissions (chmod 777)',
        '/(Access-Control-Allow-Origin:\s*\*|CORS:\s*\*)/i' => 'Permissive wildcard CORS configured',
        '/disable-security/i' => 'Security disabling flag detected',
    ];

    /**
     * Perform deterministic static auditing on a candidate unified diff.
     */
    public function audit(string $patchDiff): DiffAuditResultDTO
    {
        $violations = [];
        $lines = explode("\n", $patchDiff);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Check modified file paths
            if (str_starts_with($line, '--- a/') || str_starts_with($line, '+++ b/')) {
                $filePath = substr($line, 6);
                foreach (self::FORBIDDEN_FILE_PATTERNS as $pattern => $message) {
                    if (preg_match($pattern, $filePath)) {
                        $violations[] = "Modified forbidden infrastructure file ({$message}): {$filePath}";
                    }
                }
            }

            // Check added lines only (excluding diff header +++)
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $addedContent = substr($line, 1);
                foreach (self::FORBIDDEN_CODE_PATTERNS as $pattern => $message) {
                    if (preg_match($pattern, $addedContent)) {
                        $violations[] = "{$message} on line: {$trimmed}";
                    }
                }
            }
        }

        return new DiffAuditResultDTO(
            passed: count($violations) === 0,
            violations: $violations,
            metadata: [
                'rules_evaluated' => count(self::FORBIDDEN_FILE_PATTERNS) + count(self::FORBIDDEN_CODE_PATTERNS),
                'lines_evaluated' => count($lines),
            ],
        );
    }
}
