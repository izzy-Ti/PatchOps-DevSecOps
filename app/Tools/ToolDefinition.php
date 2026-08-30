<?php

namespace App\Tools;

use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;

readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<int, AgentRole>  $allowedAgents
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public ToolPermission $requiredPermission,
        public array $allowedAgents,
        public RiskLevel $riskLevel = RiskLevel::LOW,
    ) {}

    /**
     * Determine if an agent role is authorized to access and execute this tool.
     */
    public function isAllowedFor(AgentRole $role): bool
    {
        return in_array($role, $this->allowedAgents, true);
    }

    /**
     * Determine if this tool carries heightened operational or security risk.
     */
    public function isHighRisk(): bool
    {
        return in_array($this->riskLevel, [RiskLevel::HIGH, RiskLevel::CRITICAL], true);
    }

    /**
     * Convert to canonical LLM Tool calling schema (Anthropic Claude compatible).
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function toLlmToolSchema(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }

    /**
     * Alias for Anthropic Claude Tool schema.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function toAnthropicSchema(): array
    {
        return $this->toLlmToolSchema();
    }

    /**
     * Convert to OpenAI Function Calling format.
     *
     * @return array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    public function toOpenAISchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->inputSchema,
            ],
        ];
    }
}
