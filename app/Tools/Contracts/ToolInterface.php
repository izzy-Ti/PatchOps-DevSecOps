<?php

namespace App\Tools\Contracts;

use App\Models\Incident;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

interface ToolInterface
{
    /**
     * Get the authoritative ToolDefinition metadata for this tool.
     */
    public function definition(): ToolDefinition;

    /**
     * Unique machine-readable tool identifier (e.g. 'github.get_file', 'sandbox.execute').
     */
    public function name(): string;

    /**
     * Natural language description of what the tool accomplishes for LLM reasoning.
     */
    public function description(): string;

    /**
     * JSON Schema specification of expected arguments.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    /**
     * Granular capability permission required to execute this tool.
     */
    public function requiredPermission(): ToolPermission;

    /**
     * Execute the tool with validated arguments and incident domain context.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments, Incident $context): array;
}
