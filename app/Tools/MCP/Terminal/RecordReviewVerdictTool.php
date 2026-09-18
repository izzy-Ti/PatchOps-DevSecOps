<?php

namespace App\Tools\MCP\Terminal;

use App\DTOs\ReviewResultDTO;
use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

class RecordReviewVerdictTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: $this->name(),
            description: $this->description(),
            inputSchema: $this->parametersSchema(),
            requiredPermission: $this->requiredPermission(),
            allowedAgents: [
                AgentRole::REVIEWER,
                AgentRole::VALIDATION,
            ],
            riskLevel: RiskLevel::LOW,
        );
    }

    public function name(): string
    {
        return 'record_review_verdict';
    }

    public function description(): string
    {
        return 'Submit the final adversarial review verdict, mitigation proof, regression checks, and synthesizer guidance.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'approved' => [
                    'type' => 'boolean',
                    'description' => 'True if the patch mitigates the vulnerability, introduces zero regressions, and passes all checks.',
                ],
                'verdict_summary' => [
                    'type' => 'string',
                    'description' => 'Concise summary of the audit verdict and findings.',
                ],
                'vulnerability_mitigated' => [
                    'type' => 'boolean',
                    'description' => 'True if the original vulnerability reproduction fails / exploit is mitigated.',
                ],
                'regressions_detected' => [
                    'type' => 'boolean',
                    'description' => 'True if existing test suites or functionality broke due to the candidate patch.',
                ],
                'build_passed' => [
                    'type' => 'boolean',
                    'description' => 'True if build, compilation, and typecheck steps succeeded.',
                ],
                'checks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'check_name' => ['type' => 'string'],
                            'command' => ['type' => 'string'],
                            'exit_code' => ['type' => 'integer'],
                            'passed' => ['type' => 'boolean'],
                            'details' => ['type' => 'string'],
                        ],
                        'required' => ['check_name', 'passed'],
                    ],
                    'description' => 'Granular execution results for each verification check.',
                ],
                'failure_evidence' => [
                    'type' => 'object',
                    'properties' => [
                        'stdout' => ['type' => 'string'],
                        'stderr' => ['type' => 'string'],
                        'failed_tests' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                        'synthesizer_guidance' => [
                            'type' => 'string',
                            'description' => 'Actionable feedback for the Patch Synthesizer explaining what failed.',
                        ],
                    ],
                    'description' => 'Detailed failure diagnostics if verification fails.',
                ],
            ],
            'required' => ['approved', 'verdict_summary', 'vulnerability_mitigated', 'regressions_detected', 'build_passed'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::SANDBOX_EXECUTE;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments, Incident $context): array
    {
        $dto = ReviewResultDTO::fromArray($arguments);

        if ($context->exists) {
            $context->metadata = array_merge($context->metadata ?? [], [
                'review_verdict' => $dto->toArray(),
                'validation_summary' => $dto->verdictSummary,
                'vulnerability_mitigated' => $dto->vulnerabilityMitigated,
                'regressions_detected' => $dto->regressionsDetected,
                'reviewed_at' => now()->toIso8601String(),
            ]);
            $context->save();
        }

        return [
            'status' => 'recorded',
            'approved' => $dto->approved,
            'verdict_summary' => $dto->verdictSummary,
            'data' => $dto->toArray(),
        ];
    }
}
