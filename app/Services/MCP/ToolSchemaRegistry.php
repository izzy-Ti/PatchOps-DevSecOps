<?php

namespace App\Services\MCP;

use App\Tools\ToolRegistry;

class ToolSchemaRegistry
{
    public function __construct(
        protected ?ToolRegistry $registry = null,
    ) {
        $this->registry ??= app(ToolRegistry::class);
    }

    /**
     * Converts MCP JSON Schema tools to Gemini OpenAPI format (functionDeclarations).
     *
     * @param  array<int, string|array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    public function formatForGemini(array $tools): array
    {
        $declarations = [];

        foreach ($tools as $tool) {
            $toolSchema = is_array($tool) ? $tool : $this->getSchemaForTool($tool);

            if (! $toolSchema) {
                continue;
            }

            $name = $toolSchema['name'] ?? (is_string($tool) ? $tool : 'unnamed_tool');
            $description = $toolSchema['description'] ?? '';
            $params = $toolSchema['input_schema'] ?? $toolSchema['parameters'] ?? [];

            $properties = $this->convertProperties($params['properties'] ?? []);
            $required = $params['required'] ?? [];

            $declaration = [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => $properties,
                ],
            ];

            if (! empty($required)) {
                $declaration['parameters']['required'] = $required;
            }

            $declarations[] = $declaration;
        }

        return $declarations;
    }

    /**
     * Retrieve tool schema from the internal ToolRegistry.
     *
     * @return array<string, mixed>|null
     */
    public function getSchemaForTool(string $name): ?array
    {
        if (! $this->registry->has($name)) {
            return null;
        }

        $tool = $this->registry->get($name);
        $definition = $tool->definition();

        return [
            'name' => $definition->name,
            'description' => $definition->description,
            'parameters' => $definition->inputSchema,
        ];
    }

    /**
     * Recursively convert JSON schema properties to Gemini OpenAPI uppercase types.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected function convertProperties(array $properties): array
    {
        $converted = [];

        foreach ($properties as $key => $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $type = strtoupper((string) ($spec['type'] ?? 'STRING'));

            $prop = [
                'type' => $type,
                'description' => (string) ($spec['description'] ?? ''),
            ];

            if (isset($spec['enum']) && is_array($spec['enum'])) {
                $prop['enum'] = array_values($spec['enum']);
            }

            if (isset($spec['items']) && is_array($spec['items'])) {
                $itemType = strtoupper((string) ($spec['items']['type'] ?? 'STRING'));
                $prop['items'] = [
                    'type' => $itemType,
                ];

                if (isset($spec['items']['properties']) && is_array($spec['items']['properties'])) {
                    $prop['items']['properties'] = $this->convertProperties($spec['items']['properties']);
                }
            }

            if (isset($spec['properties']) && is_array($spec['properties'])) {
                $prop['properties'] = $this->convertProperties($spec['properties']);
            }

            $converted[$key] = $prop;
        }

        return $converted;
    }
}
