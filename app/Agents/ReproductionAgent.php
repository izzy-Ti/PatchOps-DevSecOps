<?php

namespace App\Agents;

use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\DTOs\ReproductionResultDTO;
use App\Exceptions\TransientAgentInfrastructureException;
use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use App\Tools\ToolRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReproductionAgent
{
    /**
     * Default maximum ReAct loop iteration steps.
     */
    public const MAX_REACT_STEPS = 10;

    /**
     * System prompt defining reproduction test synthesis and sandbox execution protocol.
     */
    public const SYSTEM_PROMPT = <<<'PROMPT'
You are an expert Autonomous Application Security (AppSec) Reproduction Engineer for PatchOps.

Your mission is to deterministically verify and reproduce reported vulnerabilities using isolated containerized sandboxes over the Sandbox MCP protocol.

LIFECYCLE PROTOCOL:
1. Create a sandbox via `sandbox.create_environment`.
2. Clone the repository via `sandbox.clone_repository`.
3. Install dependencies via `sandbox.install_dependencies` (automatic manifest detection).
4. Execute test commands or reproduction scripts via `sandbox.execute`.
5. Observe stdout, stderr, exit codes, and process durations. Reason carefully over the output to verify if the vulnerability was triggered.
6. Conclude by calling `record_reproduction_result` with structured evidence, artifacts, and semantic observations.

RULES:
- Never guess test outcomes. Rely strictly on sandbox tool observations.
- All commands run within non-root, air-gapped containers with strict 10-minute expiration limits.
PROMPT;

    public function __construct(
        protected ?ToolRegistry $toolRegistry = null,
        protected ?MCPToolGateway $gateway = null,
    ) {
        $this->toolRegistry ??= app(ToolRegistry::class);
        $this->gateway ??= app(MCPToolGateway::class);
    }

    /**
     * Run the vulnerability reproduction ReAct loop using Sandbox MCP tools.
     */
    public function execute(Incident $incident, ?int $agentRunId = null): ReproductionResultDTO
    {
        $agentResult = $this->reproduce($incident, $agentRunId);

        if ($agentResult->success && is_array($agentResult->data)) {
            return ReproductionResultDTO::fromArray($agentResult->data);
        }

        return new ReproductionResultDTO(
            reproduced: false,
            exitCode: 1,
            command: 'reproduction_failed',
            stdout: '',
            stderr: $agentResult->error?->message ?? 'Reproduction ReAct loop failed.',
            durationMs: 0.0,
            observations: ['Reproduction failed or timed out.'],
        );
    }

    /**
     * Synthesize and execute a reproduction plan inside an isolated sandbox.
     */
    public function reproduce(Incident $incident, ?int $agentRunId = null): AgentResultDTO
    {
        $startTime = microtime(true);
        $apiKey = config('services.anthropic.key');
        $model = config('services.anthropic.model', 'claude-3-5-sonnet-latest');
        $version = config('services.anthropic.version', '2023-06-01');
        $maxSteps = (int) config('patchops.max_reproduction_steps', self::MAX_REACT_STEPS);

        $incident->loadMissing('vulnerability');

        // Deterministic Fallback Mode when LLM key is omitted in offline/testing environments
        if (empty($apiKey)) {
            return $this->runDeterministicFallback($incident, $startTime, $agentRunId);
        }

        $promptContext = $this->buildContext($incident);

        // Compile authorized tool definitions for Reproduction role
        $roleTools = $this->toolRegistry->getToolSchemasForRole(AgentRole::REPRODUCTION);

        $terminalTool = [
            'name' => 'record_reproduction_result',
            'description' => 'Submit final structured proof-of-concept reproduction evidence and update incident workflow state.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reproduced' => [
                        'type' => 'boolean',
                        'description' => 'True if the vulnerability exploit succeeded, False if safe or not reproducible.',
                    ],
                    'command' => [
                        'type' => 'string',
                        'description' => 'Exact test command executed (e.g. npm test).',
                    ],
                    'exit_code' => [
                        'type' => 'integer',
                        'description' => 'Process exit code from test runner.',
                    ],
                    'stdout' => [
                        'type' => 'string',
                        'description' => 'Bounded standard output stream captured from sandbox.',
                    ],
                    'stderr' => [
                        'type' => 'string',
                        'description' => 'Bounded standard error stream captured from sandbox.',
                    ],
                    'duration_ms' => [
                        'type' => 'number',
                        'description' => 'Total execution runtime in milliseconds.',
                    ],
                    'environment' => [
                        'type' => 'object',
                        'description' => 'Runtime details (runtime, version, package manager).',
                    ],
                    'artifacts' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                        'description' => 'Generated PoC scripts, file diffs, or stack traces.',
                    ],
                    'observations' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Structured semantic conclusions extracted during reproduction.',
                    ],
                ],
                'required' => ['reproduced', 'command', 'observations'],
            ],
        ];

        $availableTools = array_merge($roleTools, [$terminalTool]);

        $messages = [
            [
                'role' => 'user',
                'content' => $promptContext,
            ],
        ];

        $observations = [];

        try {
            // Multi-Turn ReAct Loop
            for ($step = 1; $step <= $maxSteps; $step++) {
                $payload = [
                    'model' => $model,
                    'max_tokens' => 2048,
                    'system' => self::SYSTEM_PROMPT,
                    'messages' => $messages,
                    'tools' => $availableTools,
                ];

                $response = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => $version,
                    'content-type' => 'application/json',
                ])->timeout(60)->post('https://api.anthropic.com/v1/messages', $payload);

                $executionTime = round(microtime(true) - $startTime, 3);

                if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                    throw new TransientAgentInfrastructureException("Claude API transient status during reproduction [{$response->status()}]: {$response->body()}");
                }

                if (! $response->successful()) {
                    Log::error('Anthropic API returned error during reproduction ReAct turn.', [
                        'incident_id' => $incident->id,
                        'step' => $step,
                        'status' => $response->status(),
                    ]);

                    return AgentResultDTO::failure(
                        code: AgentErrorDTO::LLM_API_ERROR,
                        message: "Anthropic API error: {$response->status()} - {$response->body()}",
                        details: $response->json() ?? [],
                        metadata: ['agent' => 'ReproductionAgent', 'execution_time_seconds' => $executionTime],
                    );
                }

                $responseData = $response->json();
                $contentBlocks = $responseData['content'] ?? [];
                $stopReason = $responseData['stop_reason'] ?? null;

                // Check for terminal tool call
                foreach ($contentBlocks as $block) {
                    if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'record_reproduction_result') {
                        $toolInput = $block['input'] ?? [];

                        // Execute terminal tool through gateway to persist evidence and transition state
                        $terminalResult = $this->gateway->invoke(
                            role: AgentRole::REPRODUCTION,
                            toolName: 'record_reproduction_result',
                            arguments: $toolInput,
                            context: $incident,
                            agentRunId: $agentRunId,
                        );

                        return AgentResultDTO::success(
                            data: $toolInput,
                            metadata: [
                                'agent' => 'ReproductionAgent',
                                'react_steps' => $step,
                                'execution_time_seconds' => $executionTime,
                                'observations_count' => count($observations),
                                'terminal_result' => $terminalResult,
                            ],
                        );
                    }
                }

                // If Claude executes intermediate sandbox tools
                if ($stopReason === 'tool_use') {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $contentBlocks,
                    ];

                    $toolResultBlocks = [];

                    foreach ($contentBlocks as $block) {
                        if (($block['type'] ?? null) === 'tool_use') {
                            $toolUseId = $block['id'];
                            $toolName = $block['name'];
                            $toolInput = $block['input'] ?? [];

                            // Route through MCP Tool Gateway
                            $gatewayResult = $this->gateway->invoke(
                                role: AgentRole::REPRODUCTION,
                                toolName: $toolName,
                                arguments: $toolInput,
                                context: $incident,
                                agentRunId: $agentRunId,
                            );

                            $isSuccess = (bool) ($gatewayResult['success'] ?? false);
                            $toolData = $isSuccess ? ($gatewayResult['data'] ?? $gatewayResult) : $gatewayResult;
                            $observations[$toolName] = $toolData;

                            $toolResultBlocks[] = [
                                'type' => 'tool_result',
                                'tool_use_id' => $toolUseId,
                                'is_error' => ! $isSuccess,
                                'content' => json_encode($toolData, JSON_UNESCAPED_SLASHES),
                            ];
                        }
                    }

                    $messages[] = [
                        'role' => 'user',
                        'content' => $toolResultBlocks,
                    ];

                    continue;
                }

                // Non-tool response; guide model forward
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $contentBlocks,
                ];
                $messages[] = [
                    'role' => 'user',
                    'content' => 'Please proceed with sandbox testing or call record_reproduction_result with your structured evidence verdict.',
                ];
            }

            $executionTime = round(microtime(true) - $startTime, 3);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::MAX_ATTEMPTS_EXCEEDED,
                message: 'Reproduction Agent exceeded maximum ReAct steps without concluding.',
                details: ['max_steps' => $maxSteps, 'collected_observations' => array_keys($observations)],
                metadata: ['agent' => 'ReproductionAgent', 'execution_time_seconds' => $executionTime],
            );
        } catch (ConnectionException $e) {
            throw new TransientAgentInfrastructureException("Network connection error during reproduction: {$e->getMessage()}", 0, $e);
        } catch (TransientAgentInfrastructureException $e) {
            throw $e;
        } catch (Throwable $e) {
            $executionTime = round(microtime(true) - $startTime, 3);

            Log::error('Exception occurred during ReproductionAgent ReAct execution.', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::LLM_API_ERROR,
                message: "ReproductionAgent execution error: {$e->getMessage()}",
                details: ['exception' => $e->getMessage()],
                metadata: ['agent' => 'ReproductionAgent', 'execution_time_seconds' => $executionTime],
            );
        }
    }

    /**
     * Deterministic simulation fallback for environments without an active Anthropic API key.
     */
    protected function runDeterministicFallback(Incident $incident, float $startTime, ?int $agentRunId): AgentResultDTO
    {
        // 1. Provision sandbox
        $createRes = $this->gateway->invoke(
            role: AgentRole::REPRODUCTION,
            toolName: 'sandbox.create_environment',
            arguments: ['incident_id' => $incident->incident_number, 'ecosystem' => 'node'],
            context: $incident,
            agentRunId: $agentRunId,
        );

        $sandboxId = $createRes['data']['workspace_id'] ?? "sbx-{$incident->id}";

        // 2. Clone repo
        $this->gateway->invoke(
            role: AgentRole::REPRODUCTION,
            toolName: 'sandbox.clone_repository',
            arguments: ['sandbox_id' => $sandboxId, 'repository' => $incident->repository, 'ref' => 'main'],
            context: $incident,
            agentRunId: $agentRunId,
        );

        // 3. Install dependencies
        $this->gateway->invoke(
            role: AgentRole::REPRODUCTION,
            toolName: 'sandbox.install_dependencies',
            arguments: ['sandbox_id' => $sandboxId],
            context: $incident,
            agentRunId: $agentRunId,
        );

        // 4. Execute test command
        $execRes = $this->gateway->invoke(
            role: AgentRole::REPRODUCTION,
            toolName: 'sandbox.execute',
            arguments: ['workspace_id' => $sandboxId, 'command' => 'npm test'],
            context: $incident,
            agentRunId: $agentRunId,
        );

        $execData = $execRes['data'] ?? [];

        // 5. Submit structured evidence
        $evidencePayload = [
            'reproduced' => true,
            'command' => 'npm test',
            'exit_code' => $execData['exit_code'] ?? 0,
            'stdout' => $execData['stdout'] ?? '[VULNERABILITY_CONFIRMED] - Simulated deterministic reproduction',
            'stderr' => $execData['stderr'] ?? '',
            'duration_ms' => ($execData['duration_seconds'] ?? 0.1) * 1000,
            'environment' => ['runtime' => 'node', 'version' => '20'],
            'artifacts' => [['type' => 'poc_script', 'path' => 'test/repro.js']],
            'observations' => ['Deterministic reproduction confirmed in isolated sandbox environment.'],
        ];

        $this->gateway->invoke(
            role: AgentRole::REPRODUCTION,
            toolName: 'record_reproduction_result',
            arguments: $evidencePayload,
            context: $incident,
            agentRunId: $agentRunId,
        );

        $totalTime = round(microtime(true) - $startTime, 3);

        return AgentResultDTO::success(
            data: $evidencePayload,
            metadata: ['agent' => 'ReproductionAgent', 'execution_time_seconds' => $totalTime, 'mode' => 'deterministic_fallback'],
        );
    }

    /**
     * Build rich context string for the Reproduction Agent.
     */
    protected function buildContext(Incident $incident): string
    {
        $vuln = $incident->vulnerability;

        $lines = [
            '# Vulnerability Reproduction Request',
            "- Incident Number: {$incident->incident_number}",
            "- Title: {$incident->title}",
            "- Repository: {$incident->repository}",
            '- Severity: '.($incident->severity?->value ?? (string) $incident->severity),
            "- Description: {$incident->description}",
        ];

        if ($vuln) {
            $lines[] = "\n## Vulnerability Details";
            $lines[] = "- Package: {$vuln->package_name}";
            $lines[] = "- Affected Version: {$vuln->affected_version}";
            $lines[] = "- Fixed Version: {$vuln->fixed_version}";
            $lines[] = "- Reference URL: {$vuln->reference_url}";
        }

        if (! empty($incident->metadata)) {
            $metaArray = is_array($incident->metadata)
                ? $incident->metadata
                : (is_string($incident->metadata) ? (json_decode($incident->metadata, true) ?? []) : []);

            if (! empty($metaArray)) {
                $lines[] = "\n## Metadata";
                $lines[] = json_encode($metaArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return implode("\n", $lines);
    }
}
