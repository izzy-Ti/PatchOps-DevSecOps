<?php

namespace App\Tools;

use App\Tools\Contracts\ToolInterface;
use App\Tools\Permissions\ToolPermission;

readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parametersSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parametersSchema,
        public ToolPermission $requiredPermission,
    ) {}

    /**
     * Build a ToolDefinition from a ToolInterface instance.
     */
    public static function fromTool(ToolInterface $tool): self
    {
        return new self(
            name: $tool->name(),
            description: $tool->description(),
            parametersSchema: $tool->parametersSchema(),
            requiredPermission: $tool->requiredPermission(),
        );
    }

    /**
     * Format schema for Anthropic Claude Tool Calling.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function toAnthropicSchema(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->parametersSchema,
        ];
    }

    /**
     * Format schema for OpenAI Function Calling.
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
                'parameters' => $this->parametersSchema,
            ],
        ];
    }
}
