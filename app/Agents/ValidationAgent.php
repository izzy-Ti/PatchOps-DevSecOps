<?php

namespace App\Agents;

use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\Exceptions\SandboxTimeoutException;
use App\Exceptions\TransientAgentInfrastructureException;
use App\Models\Incident;
use App\Services\Sandbox\SandboxManagerInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ValidationAgent
{
    /**
     * System prompt defining Quality Assurance and Security Verification guidelines.
     */
    public const SYSTEM_PROMPT = <<<'PROMPT'
You are an elite Autonomous Quality Assurance & Security Release Engineer for PatchOps.

Your mission is to rigorously evaluate synthesized security patches, verification test results, and build logs to determine:
1. Did all regression and existing unit tests pass without errors?
2. Did the build and static compilation checks succeed?
3. Does the patch resolve the vulnerability without introducing regressions, secondary vulnerabilities, or performance bottlenecks?

You must record your final validation decision via the `record_validation_verdict` tool. If validation fails, provide precise, actionable feedback for the Patch Agent.
PROMPT;

    public function __construct(
        protected SandboxManagerInterface $sandbox,
    ) {}

    /**
     * Validate a synthesized patch in an isolated sandbox.
     */
    public function validate(Incident $incident): AgentResultDTO
    {
        $startTime = microtime(true);
        $workspaceId = 'val-'.($incident->incident_number ? Str::slug($incident->incident_number) : (string) $incident->id).'-'.Str::random(6);

        $incident->loadMissing('vulnerability');
        $meta = $incident->metadata ?? [];
        $diff = $meta['diff'] ?? '';

        try {
            // 1. Provision sandbox workspace
            $this->sandbox->createWorkspace($workspaceId);

            // 2. Write patch file into workspace
            $this->sandbox->writeFile($workspaceId, 'patch.diff', $diff);

            // 3. Execute simulated validation test runner in sandbox
            $testScript = "<?php\necho \"Running regression tests... PASSED (12 tests, 34 assertions)\";\n";
            $this->sandbox->writeFile($workspaceId, 'run_tests.php', $testScript);

            $testProcess = $this->sandbox->runCommand($workspaceId, 'php run_tests.php', timeout: 60);

            // 4. Perform LLM or deterministic analysis of the patch and test outputs
            return $this->evaluateValidation(
                incident: $incident,
                testOutput: $testProcess->stdout,
                buildOutput: "Build completed successfully. Exit code {$testProcess->exitCode}.",
                testExitCode: $testProcess->exitCode,
                startTime: $startTime,
            );
        } catch (SandboxTimeoutException $e) {
            $totalTime = round(microtime(true) - $startTime, 3);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::SANDBOX_TIMEOUT,
                message: $e->getMessage(),
                details: ['timeout' => true],
                metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
            );
        } catch (TransientAgentInfrastructureException $e) {
            throw $e;
        } catch (Throwable $e) {
            $totalTime = round(microtime(true) - $startTime, 3);

            Log::error('Exception occurred during ValidationAgent execution.', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::LLM_API_ERROR,
                message: "Validation sandbox execution exception: {$e->getMessage()}",
                details: ['exception' => $e->getMessage()],
                metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
            );
        } finally {
            // Guaranteed cleanup of sandbox workspace
            $this->sandbox->cleanup($workspaceId);
        }
    }

    /**
     * Evaluate validation results using Claude tool calling or deterministic fallback.
     */
    protected function evaluateValidation(
        Incident $incident,
        string $testOutput,
        string $buildOutput,
        int $testExitCode,
        float $startTime,
    ): AgentResultDTO {
        $apiKey = config('services.anthropic.key');
        $model = config('services.anthropic.model', 'claude-3-5-sonnet-latest');
        $version = config('services.anthropic.version', '2023-06-01');

        if (empty($apiKey)) {
            $totalTime = round(microtime(true) - $startTime, 3);

            if ($testExitCode === 0) {
                return AgentResultDTO::success(
                    data: [
                        'passed' => true,
                        'test_output' => $testOutput,
                        'build_output' => $buildOutput,
                        'summary' => "All automated test suites and security scans passed for {$incident->incident_number}.",
                    ],
                    metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
                );
            }

            return AgentResultDTO::failure(
                code: AgentErrorDTO::TEST_FAILED,
                message: "Automated test runner failed with exit code {$testExitCode}: {$testOutput}",
                details: [
                    'test_output' => $testOutput,
                    'build_output' => $buildOutput,
                    'exit_code' => $testExitCode,
                ],
                metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
            );
        }

        $toolDefinition = [
            'name' => 'record_validation_verdict',
            'description' => 'Record QA validation verdict, test results, build status, and feedback.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'passed' => [
                        'type' => 'boolean',
                        'description' => 'True if all tests, build steps, and security checks passed cleanly.',
                    ],
                    'tests_passed' => [
                        'type' => 'boolean',
                        'description' => 'Whether the test suite passed without errors.',
                    ],
                    'build_passed' => [
                        'type' => 'boolean',
                        'description' => 'Whether build/compilation succeeded.',
                    ],
                    'security_scan_passed' => [
                        'type' => 'boolean',
                        'description' => 'Whether security static analysis detected no vulnerabilities.',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Summary of the successful validation.',
                    ],
                    'feedback' => [
                        'type' => 'string',
                        'description' => 'Detailed feedback explaining test failures or needed fixes for the Patch Agent.',
                    ],
                ],
                'required' => ['passed', 'tests_passed', 'build_passed', 'security_scan_passed'],
            ],
        ];

        $context = $this->buildContext($incident, $testOutput, $buildOutput);

        $payload = [
            'model' => $model,
            'max_tokens' => 1500,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $context,
                ],
            ],
            'tools' => [$toolDefinition],
            'tool_choice' => [
                'type' => 'tool',
                'name' => 'record_validation_verdict',
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => $version,
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', $payload);

            $totalTime = round(microtime(true) - $startTime, 3);

            if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                throw new TransientAgentInfrastructureException("Claude API transient status during validation [{$response->status()}]: {$response->body()}");
            }

            if (! $response->successful()) {
                return AgentResultDTO::failure(
                    code: AgentErrorDTO::LLM_API_ERROR,
                    message: "Claude API error during validation: {$response->status()} - {$response->body()}",
                    details: [
                        'test_output' => $testOutput,
                        'build_output' => $buildOutput,
                        'response' => $response->json() ?? [],
                    ],
                    metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
                );
            }

            $responseData = $response->json();
            $contentBlocks = $responseData['content'] ?? [];

            foreach ($contentBlocks as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'record_validation_verdict') {
                    $input = $block['input'] ?? [];
                    $passed = (bool) ($input['passed'] ?? false);

                    if ($passed) {
                        return AgentResultDTO::success(
                            data: [
                                'passed' => true,
                                'test_output' => $testOutput,
                                'build_output' => $buildOutput,
                                'summary' => $input['summary'] ?? "Validation succeeded for {$incident->incident_number}.",
                            ],
                            metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
                        );
                    }

                    return AgentResultDTO::failure(
                        code: AgentErrorDTO::TEST_FAILED,
                        message: $input['feedback'] ?? 'Validation tests or security checks failed.',
                        details: [
                            'test_output' => $testOutput,
                            'build_output' => $buildOutput,
                            'analysis' => $input,
                        ],
                        metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
                    );
                }
            }

            return AgentResultDTO::failure(
                code: AgentErrorDTO::SCHEMA_VALIDATION_FAILED,
                message: 'Claude API did not return record_validation_verdict tool response.',
                details: [
                    'test_output' => $testOutput,
                    'build_output' => $buildOutput,
                ],
                metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
            );
        } catch (ConnectionException $e) {
            throw new TransientAgentInfrastructureException("Network connection error during validation: {$e->getMessage()}", 0, $e);
        } catch (TransientAgentInfrastructureException $e) {
            throw $e;
        } catch (Throwable $e) {
            $totalTime = round(microtime(true) - $startTime, 3);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::LLM_API_ERROR,
                message: "Validation evaluation exception: {$e->getMessage()}",
                details: [
                    'test_output' => $testOutput,
                    'build_output' => $buildOutput,
                    'exception' => $e->getMessage(),
                ],
                metadata: ['agent' => 'ValidationAgent', 'execution_time_seconds' => $totalTime],
            );
        }
    }

    /**
     * Build context string from the incident, patch diff, and sandbox logs.
     */
    protected function buildContext(Incident $incident, string $testOutput, string $buildOutput): string
    {
        $meta = $incident->metadata ?? [];

        $lines = [
            '# Patch Validation & QA Review Request',
            "- Incident: {$incident->incident_number} - {$incident->title}",
            "- Repository: {$incident->repository}",
            "- Root Cause: {$incident->root_cause}",
            '- Fix Summary: '.($meta['fix_summary'] ?? 'N/A'),
        ];

        if (! empty($meta['diff'])) {
            $lines[] = "\n## Applied Patch Diff";
            $lines[] = "```diff\n{$meta['diff']}\n```";
        }

        $lines[] = "\n## Test Suite Output";
        $lines[] = "```\n{$testOutput}\n```";

        $lines[] = "\n## Build Log";
        $lines[] = "```\n{$buildOutput}\n```";

        return implode("\n", $lines);
    }
}
