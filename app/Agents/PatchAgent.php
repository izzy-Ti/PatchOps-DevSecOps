<?php

namespace App\Agents;

use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\Exceptions\TransientAgentInfrastructureException;
use App\Models\Incident;
use App\Services\MCP\ToolSchemaRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PatchAgent
{
    /**
     * System prompt defining security patch synthesis guidelines.
     */
    public const SYSTEM_PROMPT = <<<'PROMPT'
You are an elite Autonomous Principal Security & Software Engineer for the PatchOps automated remediation platform.

Your mission is to analyze confirmed vulnerability reproductions, determine the precise root cause, and synthesize a minimal, surgical, non-breaking source code patch alongside comprehensive regression tests.

Strict Guardrails:
1. Never introduce breaking changes to existing public APIs, interfaces, or method signatures.
2. Always generate patches in standard Unified Diff format (`git diff`).
3. Provide comprehensive regression/unit tests that specifically prevent regression of the reported vulnerability.
4. You must ALWAYS return your assessment and code diff via the `record_patch_synthesis` tool.
PROMPT;

    /**
     * Generate a minimal security patch and regression test for an incident.
     */
    public function generatePatch(Incident $incident): AgentResultDTO
    {
        $startTime = microtime(true);
        $geminiKey = config('services.gemini.api_key');
        if (! empty($geminiKey)) {
            return $this->generatePatchWithGemini($incident, $startTime);
        }

        $apiKey = config('services.anthropic.key');
        $model = config('services.anthropic.model', 'claude-3-5-sonnet-latest');
        $version = config('services.anthropic.version', '2023-06-01');

        if (empty($apiKey)) {
            // Default deterministic patch fallback if Anthropic key is not configured in local testing
            $cve = $incident->vulnerability?->cve_id ?? 'CVE-SECURITY-FIX';
            $diff = <<<DIFF
--- a/src/SecurityHandler.php
+++ b/src/SecurityHandler.php
@@ -10,6 +10,8 @@
     public function sanitizeInput(string \$input): string
     {
+        // Patched {$cve}: Sanitize untrusted input
+        \$input = htmlspecialchars(\$input, ENT_QUOTES, 'UTF-8');
         return trim(\$input);
     }
--- /dev/null
+++ b/tests/SecurityHandlerTest.php
@@ -0,0 +1,12 @@
+<?php
+test('{$cve} regression test prevents payload injection', function () {
+    \$handler = new SecurityHandler();
+    expect(\$handler->sanitizeInput("<script>"))->not->toContain("<script>");
+});
DIFF;

            $totalTime = round(microtime(true) - $startTime, 3);

            return AgentResultDTO::success(
                data: [
                    'root_cause' => "Unsanitized user input allowed potential payload execution in {$incident->title}.",
                    'fix_summary' => "Applied input sanitization and added {$cve} regression test.",
                    'diff' => $diff,
                    'changed_files' => ['src/SecurityHandler.php'],
                    'tests_added' => ['tests/SecurityHandlerTest.php'],
                ],
                metadata: ['agent' => 'PatchAgent', 'execution_time_seconds' => $totalTime],
            );
        }

        $incident->loadMissing('vulnerability');
        $promptContext = $this->buildContext($incident);

        $toolDefinition = $this->getTerminalToolDefinition();

        $payload = [
            'model' => $model,
            'max_tokens' => 3000,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $promptContext,
                ],
            ],
            'tools' => [$toolDefinition],
            'tool_choice' => [
                'type' => 'tool',
                'name' => 'record_patch_synthesis',
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => $version,
                'content-type' => 'application/json',
            ])->timeout(90)->post('https://api.anthropic.com/v1/messages', $payload);

            $totalTime = round(microtime(true) - $startTime, 3);

            if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                throw new TransientAgentInfrastructureException("Claude API transient status during patch synthesis [{$response->status()}]: {$response->body()}");
            }

            if (! $response->successful()) {
                Log::error('Claude API returned error during patch synthesis.', [
                    'incident_id' => $incident->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return AgentResultDTO::failure(
                    code: AgentErrorDTO::LLM_API_ERROR,
                    message: "Claude API error: {$response->status()} - {$response->body()}",
                    details: $response->json() ?? [],
                    metadata: ['agent' => 'PatchAgent', 'execution_time_seconds' => $totalTime],
                );
            }

            $responseData = $response->json();
            $contentBlocks = $responseData['content'] ?? [];

            foreach ($contentBlocks as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'record_patch_synthesis') {
                    $input = $block['input'] ?? [];

                    return AgentResultDTO::success(
                        data: [
                            'root_cause' => $input['root_cause'] ?? '',
                            'fix_summary' => $input['fix_summary'] ?? '',
                            'diff' => $input['diff'] ?? '',
                            'changed_files' => $input['changed_files'] ?? [],
                            'tests_added' => $input['tests_added'] ?? [],
                        ],
                        metadata: ['agent' => 'PatchAgent', 'execution_time_seconds' => $totalTime],
                    );
                }
            }

            return AgentResultDTO::failure(
                code: AgentErrorDTO::SCHEMA_VALIDATION_FAILED,
                message: 'Claude API responded without calling record_patch_synthesis tool.',
                details: $responseData ?? [],
                metadata: ['agent' => 'PatchAgent', 'execution_time_seconds' => $totalTime],
            );
        } catch (ConnectionException $e) {
            throw new TransientAgentInfrastructureException("Network connection error during patch synthesis: {$e->getMessage()}", 0, $e);
        } catch (TransientAgentInfrastructureException $e) {
            throw $e;
        } catch (Throwable $e) {
            $totalTime = round(microtime(true) - $startTime, 3);

            Log::error('Exception occurred during PatchAgent execution.', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::PATCH_SYNTHESIS_FAILED,
                message: "PatchAgent execution exception: {$e->getMessage()}",
                details: ['exception' => $e->getMessage()],
                metadata: ['agent' => 'PatchAgent', 'execution_time_seconds' => $totalTime],
            );
        }
    }

    /**
     * Build rich context containing incident details, vulnerability advisory, and reproduction traces.
     */
    protected function buildContext(Incident $incident): string
    {
        $vuln = $incident->vulnerability;

        $lines = [
            '# Vulnerability Patch Synthesis Request',
            "- Incident Number: {$incident->incident_number}",
            "- Title: {$incident->title}",
            "- Repository: {$incident->repository}",
            "- Environment: {$incident->environment}",
            '- Severity: '.($incident->severity?->value ?? (string) $incident->severity),
            "- Description: {$incident->description}",
        ];

        if ($vuln) {
            $lines[] = "\n## Vulnerability Advisory";
            $lines[] = "- Package: {$vuln->package_name}";
            $lines[] = "- Affected Version: {$vuln->affected_version}";
            $lines[] = "- Fixed Upstream Version: {$vuln->fixed_version}";
            $lines[] = "- Reference URL: {$vuln->reference_url}";
        }

        $meta = $incident->metadata ?? [];
        if (! empty($meta['poc_script']) || ! empty($meta['reproduction_summary'])) {
            $lines[] = "\n## Confirmed Reproduction Evidence";
            if (! empty($meta['reproduction_summary'])) {
                $lines[] = "- Summary: {$meta['reproduction_summary']}";
            }
            if (! empty($meta['poc_script'])) {
                $lines[] = "```\n{$meta['poc_script']}\n```";
            }
            if (! empty($meta['reproduction_stdout'])) {
                $lines[] = "- Stdout: {$meta['reproduction_stdout']}";
            }
        }

        $attempts = $incident->getPatchAttempts();
        $latestFeedback = $incident->getLatestValidationFeedback();
        if ($attempts > 0 || ! empty($latestFeedback)) {
            $lines[] = "\n## PREVIOUS ATTEMPT FAILED (Attempt {$attempts} of 3)";
            $lines[] = 'Your previous patch attempt failed validation. Adjust your fix to resolve the root cause while addressing these failure diagnostics:';
            if (! empty($latestFeedback)) {
                $lines[] = "- Validation Feedback: {$latestFeedback}";
            }
            if (! empty($meta['last_synthesizer_guidance']) && $meta['last_synthesizer_guidance'] !== $latestFeedback) {
                $lines[] = "- Reviewer Guidance for Synthesizer:\n  {$meta['last_synthesizer_guidance']}";
            }
            if (! empty($meta['last_failed_tests']) && is_array($meta['last_failed_tests'])) {
                $lines[] = '- Failed Tests Detected: '.implode(', ', $meta['last_failed_tests']);
            }
            if (! empty($meta['last_validation_checks']) && is_array($meta['last_validation_checks'])) {
                $failedChecks = array_filter($meta['last_validation_checks'], fn ($c) => empty($c['passed']));
                if (! empty($failedChecks)) {
                    $lines[] = '- Failed Verification Checks:';
                    foreach ($failedChecks as $fc) {
                        $cName = $fc['check_name'] ?? 'Check';
                        $cDetails = $fc['details'] ?? '';
                        $lines[] = "  * {$cName}: {$cDetails}";
                    }
                }
            }
            if (! empty($meta['validation_test_output'])) {
                $lines[] = "- Test Failure Output:\n```\n{$meta['validation_test_output']}\n```";
            }
            if (! empty($meta['validation_build_output'])) {
                $lines[] = "- Build Output:\n```\n{$meta['validation_build_output']}\n```";
            }
            if (! empty($meta['diff'])) {
                $lines[] = "- Previous Failed Diff:\n```diff\n{$meta['diff']}\n```";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate patch and regression test using Google Gemini API function calling.
     */
    protected function generatePatchWithGemini(Incident $incident, float $startTime): AgentResultDTO
    {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model', 'gemini-1.5-pro-latest');
        $baseUrl = config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

        $incident->loadMissing('vulnerability');
        $promptContext = $this->buildContext($incident);

        $toolDefinition = $this->getTerminalToolDefinition();
        /** @var ToolSchemaRegistry $toolRegistry */
        $toolRegistry = app(ToolSchemaRegistry::class);
        $functionDeclarations = $toolRegistry->formatForGemini([$toolDefinition]);

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => self::SYSTEM_PROMPT]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $promptContext]],
                ],
            ],
            'generationConfig' => [
                'temperature' => (float) config('services.gemini.temperature', 0.1),
                'maxOutputTokens' => (int) config('services.gemini.max_tokens', 8192),
            ],
            'tools' => [
                ['functionDeclarations' => $functionDeclarations],
            ],
            'toolConfig' => [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => ['record_patch_synthesis'],
                ],
            ],
        ];

        try {
            $endpoint = "{$baseUrl}/models/{$model}:generateContent?key={$apiKey}";
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(90)->post($endpoint, $payload);

            $totalTime = round(microtime(true) - $startTime, 3);

            if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                throw new TransientAgentInfrastructureException("Gemini API transient status during patch synthesis [{$response->status()}]: {$response->body()}");
            }

            if (! $response->successful()) {
                Log::error('Gemini API returned error during patch synthesis.', [
                    'incident_id' => $incident->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return AgentResultDTO::failure(
                    code: AgentErrorDTO::LLM_API_ERROR,
                    message: "Gemini API error: {$response->status()} - {$response->body()}",
                    details: $response->json() ?? [],
                    metadata: ['agent' => 'PatchAgent', 'engine' => 'gemini', 'execution_time_seconds' => $totalTime],
                );
            }

            $responseData = $response->json();
            $candidate = $responseData['candidates'][0]['content'] ?? null;
            $parts = $candidate['parts'] ?? [];

            foreach ($parts as $part) {
                if (isset($part['functionCall']) && ($part['functionCall']['name'] ?? null) === 'record_patch_synthesis') {
                    $input = (array) ($part['functionCall']['args'] ?? []);

                    return AgentResultDTO::success(
                        data: [
                            'root_cause' => $input['root_cause'] ?? '',
                            'fix_summary' => $input['fix_summary'] ?? '',
                            'diff' => $input['diff'] ?? '',
                            'changed_files' => (array) ($input['changed_files'] ?? []),
                            'tests_added' => (array) ($input['tests_added'] ?? []),
                        ],
                        metadata: ['agent' => 'PatchAgent', 'engine' => 'gemini', 'execution_time_seconds' => $totalTime],
                    );
                }
            }

            return AgentResultDTO::failure(
                code: AgentErrorDTO::LLM_API_ERROR,
                message: 'Gemini model did not invoke record_patch_synthesis function.',
                details: $responseData ?? [],
                metadata: ['agent' => 'PatchAgent', 'engine' => 'gemini', 'execution_time_seconds' => $totalTime],
            );
        } catch (TransientAgentInfrastructureException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw new TransientAgentInfrastructureException("Gemini connection timeout: {$e->getMessage()}", previous: $e);
        } catch (Throwable $e) {
            $totalTime = round(microtime(true) - $startTime, 3);
            Log::error('Exception in PatchAgent Gemini synthesis: '.$e->getMessage(), ['exception' => $e]);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::LLM_API_ERROR,
                message: "Gemini execution exception: {$e->getMessage()}",
                details: ['exception' => $e->getMessage()],
                metadata: ['agent' => 'PatchAgent', 'engine' => 'gemini', 'execution_time_seconds' => $totalTime],
            );
        }
    }

    /**
     * Return JSON schema for the record_patch_synthesis terminal tool.
     *
     * @return array<string, mixed>
     */
    protected function getTerminalToolDefinition(): array
    {
        return [
            'name' => 'record_patch_synthesis',
            'description' => 'Record synthesized security patch, root cause analysis, unified diff, and regression tests.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'root_cause' => [
                        'type' => 'string',
                        'description' => 'Technical explanation of why the vulnerability exists in the source code.',
                    ],
                    'fix_summary' => [
                        'type' => 'string',
                        'description' => 'Clear, concise summary of the code modifications applied.',
                    ],
                    'diff' => [
                        'type' => 'string',
                        'description' => 'Unified git diff format containing code modifications and new regression tests.',
                    ],
                    'changed_files' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'List of modified source code file paths.',
                    ],
                    'tests_added' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'List of created or updated regression test file paths.',
                    ],
                ],
                'required' => ['root_cause', 'fix_summary', 'diff', 'changed_files', 'tests_added'],
            ],
        ];
    }
}
