<?php

namespace App\Agents;

use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\Exceptions\TransientAgentInfrastructureException;
use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use App\Tools\ToolRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TriageAgent
{
    /**
     * Default maximum ReAct loop iteration steps.
     */
    public const MAX_REACT_STEPS = 6;

    /**
     * System prompt defining AppSec triage expertise and evaluation guidelines.
     */
    public const SYSTEM_PROMPT = <<<'PROMPT'
You are an elite Autonomous Application Security (AppSec) Triage Engineer for the PatchOps automated remediation platform.

Your mission is to perform an iterative ReAct (Reason + Act + Observe) security investigation of the reported vulnerability:
1. Query vulnerability databases and advisories via vulnerability.* tools.
2. Investigate repository structure, dependency manifests (composer.json, package.json, requirements.txt), and source code via repository.* and github.* tools.
3. Determine real-world exploitability (remote code execution, auth bypass, injection, prototype pollution, denial of service).
4. Determine production exposure (is this package part of runtime dependencies or purely build/dev tools?).
5. Assess true severity (critical, high, medium, low) based on CVSS, threat vectors, and actual codebase usage.
6. Determine remediation priority (critical, high, medium, low) balancing exploitability and blast radius.
7. Conclude your investigation by executing the `record_triage_analysis` tool to submit your final verdict.
PROMPT;

    public function __construct(
        protected ?ToolRegistry $toolRegistry = null,
        protected ?MCPToolGateway $gateway = null,
    ) {
        $this->toolRegistry ??= app(ToolRegistry::class);
        $this->gateway ??= app(MCPToolGateway::class);
    }

    /**
     * Analyze an incident using Claude API with a multi-turn ReAct investigation loop.
     */
    public function analyze(Incident $incident, ?int $agentRunId = null): AgentResultDTO
    {
        $startTime = microtime(true);
        $apiKey = config('services.anthropic.key');
        $model = config('services.anthropic.model', 'claude-3-5-sonnet-latest');
        $version = config('services.anthropic.version', '2023-06-01');
        $maxSteps = (int) config('patchops.max_triage_steps', self::MAX_REACT_STEPS);

        if (empty($apiKey)) {
            Log::warning('Anthropic API key is not configured for TriageAgent.', [
                'incident_id' => $incident->id,
            ]);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::LLM_API_ERROR,
                message: 'Anthropic API key is not configured.',
                details: [],
                metadata: ['agent' => 'TriageAgent', 'execution_time_seconds' => 0.0],
            );
        }

        $incident->loadMissing('vulnerability');
        $promptContext = $this->buildContext($incident);

        // 1. Compile authorized tool definitions for Triage role
        $roleTools = $this->toolRegistry->getToolSchemasForRole(AgentRole::TRIAGE);

        $terminalTool = [
            'name' => 'record_triage_analysis',
            'description' => 'Submit final security triage analysis, severity, priority, production exposure, and evidence after completing investigation.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'severity' => [
                        'type' => 'string',
                        'enum' => ['critical', 'high', 'medium', 'low'],
                        'description' => 'The assessed severity level of the vulnerability.',
                    ],
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['critical', 'high', 'medium', 'low'],
                        'description' => 'The remediation urgency priority.',
                    ],
                    'production_exposed' => [
                        'type' => 'boolean',
                        'description' => 'True if the vulnerable dependency or component is exposed in runtime/production.',
                    ],
                    'affected_component' => [
                        'type' => 'string',
                        'description' => 'The specific package, module, or component affected.',
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Detailed technical reasoning explaining severity, priority, and production impact.',
                    ],
                    'evidence_summary' => [
                        'type' => 'string',
                        'description' => 'Summary of findings gathered during the investigation.',
                    ],
                ],
                'required' => ['severity', 'priority', 'production_exposed', 'affected_component', 'reason'],
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
                    throw new TransientAgentInfrastructureException("Claude API transient status [{$response->status()}]: {$response->body()}");
                }

                if (! $response->successful()) {
                    Log::error('Anthropic API returned error during triage ReAct turn.', [
                        'incident_id' => $incident->id,
                        'step' => $step,
                        'status' => $response->status(),
                    ]);

                    return AgentResultDTO::failure(
                        code: AgentErrorDTO::LLM_API_ERROR,
                        message: "Anthropic API error: {$response->status()} - {$response->body()}",
                        details: $response->json() ?? [],
                        metadata: ['agent' => 'TriageAgent', 'execution_time_seconds' => $executionTime],
                    );
                }

                $responseData = $response->json();
                $contentBlocks = $responseData['content'] ?? [];
                $stopReason = $responseData['stop_reason'] ?? null;

                // Check for terminal tool call
                foreach ($contentBlocks as $block) {
                    if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'record_triage_analysis') {
                        $toolInput = $block['input'] ?? [];

                        // Store evidence in incident metadata
                        $existingMetadata = is_array($incident->metadata)
                            ? $incident->metadata
                            : (is_string($incident->metadata) ? (json_decode($incident->metadata, true) ?? []) : []);

                        $incident->metadata = array_merge($existingMetadata, [
                            'triage_evidence' => $observations,
                            'triage_result' => $toolInput,
                        ]);
                        $incident->save();

                        return AgentResultDTO::success(
                            data: $toolInput,
                            metadata: [
                                'agent' => 'TriageAgent',
                                'react_steps' => $step,
                                'execution_time_seconds' => $executionTime,
                                'observations_count' => count($observations),
                            ],
                        );
                    }
                }

                // If Claude wants to execute intermediate tools
                if ($stopReason === 'tool_use') {
                    // Append Assistant Turn
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
                            try {
                                $gatewayResult = $this->gateway->execute(
                                    role: AgentRole::TRIAGE,
                                    toolName: $toolName,
                                    arguments: $toolInput,
                                    context: $incident,
                                    agentRunId: $agentRunId,
                                );

                                $toolData = $gatewayResult['data'] ?? $gatewayResult;
                                $observations[$toolName] = $toolData;

                                $toolResultBlocks[] = [
                                    'type' => 'tool_result',
                                    'tool_use_id' => $toolUseId,
                                    'is_error' => false,
                                    'content' => json_encode($toolData, JSON_UNESCAPED_SLASHES),
                                ];
                            } catch (Throwable $toolEx) {
                                Log::warning("TriageAgent tool [{$toolName}] failed via gateway: {$toolEx->getMessage()}");
                                $observations[$toolName] = ['error' => $toolEx->getMessage()];

                                $toolResultBlocks[] = [
                                    'type' => 'tool_result',
                                    'tool_use_id' => $toolUseId,
                                    'is_error' => true,
                                    'content' => "Tool execution failed: {$toolEx->getMessage()}",
                                ];
                            }
                        }
                    }

                    // Append Tool Result Observations
                    $messages[] = [
                        'role' => 'user',
                        'content' => $toolResultBlocks,
                    ];

                    continue;
                }

                // Non-tool response; prompt model to investigate or finish triage
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $contentBlocks,
                ];
                $messages[] = [
                    'role' => 'user',
                    'content' => 'Please proceed with your investigation using available tools or call record_triage_analysis with your final verdict.',
                ];
            }

            $executionTime = round(microtime(true) - $startTime, 3);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::MAX_ATTEMPTS_EXCEEDED,
                message: 'Triage Agent exceeded maximum ReAct investigation steps without concluding.',
                details: ['max_steps' => $maxSteps, 'collected_observations' => array_keys($observations)],
                metadata: ['agent' => 'TriageAgent', 'execution_time_seconds' => $executionTime],
            );
        } catch (ConnectionException $e) {
            throw new TransientAgentInfrastructureException("Network connection error during triage: {$e->getMessage()}", 0, $e);
        } catch (TransientAgentInfrastructureException $e) {
            throw $e;
        } catch (Throwable $e) {
            $executionTime = round(microtime(true) - $startTime, 3);

            Log::error('Exception occurred during TriageAgent ReAct execution.', [
                'incident_id' => $incident->id,
                'exception' => $e->getMessage(),
            ]);

            return AgentResultDTO::failure(
                code: AgentErrorDTO::LLM_API_ERROR,
                message: "TriageAgent execution error: {$e->getMessage()}",
                details: ['exception' => $e->getMessage()],
                metadata: ['agent' => 'TriageAgent', 'execution_time_seconds' => $executionTime],
            );
        }
    }

    /**
     * Build rich domain context from the incident and vulnerability advisory.
     */
    protected function buildContext(Incident $incident): string
    {
        $vuln = $incident->vulnerability;

        $lines = [
            '# Vulnerability Incident Triage Request',
            "- Incident Number: {$incident->incident_number}",
            "- Title: {$incident->title}",
            "- Repository: {$incident->repository}",
            "- Environment: {$incident->environment}",
            '- Initial Severity: '.($incident->severity?->value ?? (string) $incident->severity),
            '- Initial Priority: '.($incident->priority?->value ?? (string) $incident->priority),
            "- Description: {$incident->description}",
        ];

        if ($vuln) {
            $lines[] = "\n## Vulnerability Details";
            $lines[] = '- Source: '.($vuln->source?->value ?? (string) $vuln->source);
            $lines[] = "- Source ID: {$vuln->source_id}";
            $lines[] = "- CVE ID: {$vuln->cve_id}";
            $lines[] = "- Package: {$vuln->package_name}";
            $lines[] = "- Vulnerable Version Range: {$vuln->affected_version}";
            $lines[] = "- Fixed Version: {$vuln->fixed_version}";
            $lines[] = "- Advisory URL: {$vuln->reference_url}";
        }

        if (! empty($incident->metadata)) {
            $metaArray = is_array($incident->metadata)
                ? $incident->metadata
                : (is_string($incident->metadata) ? (json_decode($incident->metadata, true) ?? []) : []);

            if (! empty($metaArray)) {
                $lines[] = "\n## Additional Metadata";
                $lines[] = json_encode($metaArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return implode("\n", $lines);
    }
}
