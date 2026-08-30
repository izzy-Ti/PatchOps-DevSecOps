<?php

namespace App\Agents;

use App\DTOs\TriageResultDTO;
use App\Exceptions\TransientAgentInfrastructureException;
use App\Models\Incident;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TriageAgent
{
    /**
     * System prompt defining AppSec triage expertise and evaluation guidelines.
     */
    public const SYSTEM_PROMPT = <<<'PROMPT'
You are an elite Autonomous Application Security (AppSec) Triage Engineer for the PatchOps automated remediation platform.

Your job is to analyze incoming security vulnerabilities, advisory disclosures, dependency manifests, and repository metadata to determine:
1. Real-world exploitability (remote code execution, data exfiltration, prototype pollution, denial of service).
2. Production exposure (is this package part of a runtime API/backend service or purely an internal build/dev tool?).
3. True severity (critical, high, medium, low) based on CVSS and threat vectors.
4. Remediation priority (critical, high, medium, low) balancing exploitability and blast radius.
5. Affected software component and concise technical rationale.

You must ALWAYS record your final assessment via the `record_triage_analysis` tool. Be objective, precise, and avoid speculative claims without technical evidence.
PROMPT;

    /**
     * Analyze an incident using Claude API with forced structured tool calling.
     */
    public function analyze(Incident $incident): TriageResultDTO
    {
        $apiKey = config('services.anthropic.key');
        $model = config('services.anthropic.model', 'claude-3-5-sonnet-latest');
        $version = config('services.anthropic.version', '2023-06-01');

        if (empty($apiKey)) {
            Log::warning('Anthropic API key is not configured for TriageAgent.', [
                'incident_id' => $incident->id,
            ]);

            return TriageResultDTO::failure('Anthropic API key is not configured.');
        }

        $incident->loadMissing('vulnerability');
        $promptContext = $this->buildContext($incident);

        $toolDefinition = [
            'name' => 'record_triage_analysis',
            'description' => 'Record security triage analysis, severity, priority, and blast radius for a reported vulnerability.',
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
                ],
                'required' => ['severity', 'priority', 'production_exposed', 'affected_component', 'reason'],
            ],
        ];

        $payload = [
            'model' => $model,
            'max_tokens' => 1024,
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
                'name' => 'record_triage_analysis',
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => $version,
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', $payload);

            if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                throw new TransientAgentInfrastructureException("Claude API transient status [{$response->status()}]: {$response->body()}");
            }

            if (! $response->successful()) {
                Log::error('Anthropic API returned error during triage analysis.', [
                    'incident_id' => $incident->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return TriageResultDTO::failure(
                    errorMessage: "Anthropic API error: {$response->status()} - {$response->body()}",
                    raw: $response->json() ?? [],
                );
            }

            $responseData = $response->json();
            $contentBlocks = $responseData['content'] ?? [];

            foreach ($contentBlocks as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'record_triage_analysis') {
                    $toolInput = $block['input'] ?? [];

                    return TriageResultDTO::success(
                        data: $toolInput,
                        raw: $responseData,
                    );
                }
            }

            return TriageResultDTO::failure(
                errorMessage: 'Claude API responded without calling the record_triage_analysis tool.',
                raw: $responseData,
            );
        } catch (ConnectionException $e) {
            throw new TransientAgentInfrastructureException("Network connection error during triage: {$e->getMessage()}", 0, $e);
        } catch (TransientAgentInfrastructureException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Exception occurred during TriageAgent execution.', [
                'incident_id' => $incident->id,
                'exception' => $e->getMessage(),
            ]);

            return TriageResultDTO::failure(
                errorMessage: "TriageAgent execution error: {$e->getMessage()}",
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
            "- Incident Title: {$incident->title}",
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
            $lines[] = "\n## Additional Metadata";
            $lines[] = json_encode($incident->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }
}
