<?php

namespace App\Services\Security;

class PromptInjectionGuard
{
    /**
     * Common prompt injection heuristics and delimiter patterns.
     *
     * @var array<int, string>
     */
    protected const INJECTION_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior)\s+instructions/i',
        '/disregard\s+(all\s+)?(previous|prior)\s+instructions/i',
        '/system\s+prompt\s+override/i',
        '/you\s+are\s+now\s+(in\s+)?(developer\s+mode|unrestricted\s+mode)/i',
        '/<\|im_start\|>/i',
        '/<\|im_end\|>/i',
        '/<\|endoftext\|>/i',
        '/\[INST\]/i',
        '/\[\/INST\]/i',
        '/```system/i',
    ];

    /**
     * Check if the input contains known prompt injection signatures.
     */
    public function detectInjection(string $input): bool
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize untrusted input by stripping or neutralizing known prompt injection patterns.
     */
    public function sanitize(string $input): string
    {
        $sanitized = $input;
        foreach (self::INJECTION_PATTERNS as $pattern) {
            $sanitized = preg_replace($pattern, '[NEUTRALIZED_INJECTION_PATTERN]', $sanitized) ?? $sanitized;
        }

        return $sanitized;
    }

    /**
     * Wrap untrusted external content in an isolated, delimited contextual envelope.
     */
    public function wrapContext(string $input, string $type = 'untrusted_input'): string
    {
        $sanitized = $this->sanitize($input);

        return "<UNTRUSTED_CONTENT type=\"{$type}\">\n"
            ."<![CDATA[\n"
            ."{$sanitized}\n"
            ."]]>\n"
            .'</UNTRUSTED_CONTENT>';
    }
}
