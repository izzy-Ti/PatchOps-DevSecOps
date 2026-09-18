<?php

namespace App\Services\Security;

class SecretRedactionService
{
    /**
     * Common sensitive patterns to detect and mask.
     *
     * @var array<string, string>
     */
    protected array $patterns = [
        'github_pat' => '/(ghp_[a-zA-Z0-9]{36,}|github_pat_[a-zA-Z0-9_]{82})/i',
        'aws_access_key' => '/(AKIA[0-9A-Z]{16})/i',
        'aws_secret_key' => '/(?i:aws_secret_access_key|aws_secret_key)\s*[:=]\s*["\']?([a-zA-Z0-9\/+=]{40})["\']?/',
        'bearer_token' => '/(Bearer\s+)([a-zA-Z0-9\-_\.]{20,})/i',
        'private_key' => '/(-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----)/',
        'db_connection' => '/((?:postgres|mysql|mongodb|redis):\/\/[a-zA-Z0-9_-]+:)([^@]+)(@[a-zA-Z0-9_\-\.]+)/i',
        'generic_secret' => '/(?i:(?:password|secret|api_key|token|auth_token))\s*[:=]\s*["\']?([^"\'\s,]{8,})["\']?/',
    ];

    /**
     * Redact sensitive tokens from a string.
     */
    public function redactString(string $content): string
    {
        if (empty($content)) {
            return $content;
        }

        $redacted = $content;

        // GitHub PATs
        $redacted = preg_replace($this->patterns['github_pat'], '[REDACTED_GITHUB_TOKEN]', $redacted) ?? $redacted;

        // AWS Keys
        $redacted = preg_replace($this->patterns['aws_access_key'], '[REDACTED_AWS_KEY]', $redacted) ?? $redacted;
        $redacted = preg_replace($this->patterns['aws_secret_key'], 'aws_secret_access_key=[REDACTED_AWS_SECRET]', $redacted) ?? $redacted;

        // Bearer Token
        $redacted = preg_replace_callback($this->patterns['bearer_token'], function ($matches) {
            return $matches[1].'[REDACTED_BEARER_TOKEN]';
        }, $redacted) ?? $redacted;

        // Private Key
        $redacted = preg_replace($this->patterns['private_key'], '[REDACTED_PRIVATE_KEY]', $redacted) ?? $redacted;

        // Database URI credentials
        $redacted = preg_replace_callback($this->patterns['db_connection'], function ($matches) {
            return $matches[1].'[REDACTED_PASSWORD]'.$matches[3];
        }, $redacted) ?? $redacted;

        return $redacted;
    }

    /**
     * Recursively redact values in an array or scalar.
     *
     * @param  mixed  $data
     * @return mixed
     */
    public function redact(mixed $data): mixed
    {
        if (is_string($data)) {
            return $this->redactString($data);
        }

        if (! is_array($data)) {
            return $data;
        }

        $sensitiveKeyPatterns = ['/pass/i', '/secret/i', '/token/i', '/key/i', '/auth/i', '/credential/i'];

        $result = [];
        foreach ($data as $key => $value) {
            $isSensitiveKey = false;
            if (is_string($key)) {
                foreach ($sensitiveKeyPatterns as $keyPattern) {
                    if (preg_match($keyPattern, $key)) {
                        $isSensitiveKey = true;
                        break;
                    }
                }
            }

            if ($isSensitiveKey && is_string($value) && ! empty($value)) {
                $result[$key] = '[REDACTED_SENSITIVE_VALUE]';
            } else {
                $result[$key] = $this->redact($value);
            }
        }

        return $result;
    }
}
