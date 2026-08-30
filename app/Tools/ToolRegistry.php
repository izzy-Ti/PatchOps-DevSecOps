<?php

namespace App\Tools;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Exceptions\InvalidToolArgumentException;
use App\Tools\Exceptions\ToolNotFoundException;
use App\Tools\Exceptions\UnauthorizedToolException;

class ToolRegistry
{
    /**
     * Internal catalog of registered tools keyed by tool machine name.
     *
     * @var array<string, ToolInterface>
     */
    protected array $tools = [];

    public function __construct()
    {
        $this->bootProviders();
    }

    /**
     * Register default tool providers configured in config/tools.php.
     */
    protected function bootProviders(): void
    {
        $providers = config('tools.providers', []);

        foreach ($providers as $providerClass) {
            if (class_exists($providerClass)) {
                $tool = app($providerClass);
                if ($tool instanceof ToolInterface) {
                    $this->register($tool);
                }
            }
        }
    }

    /**
     * Add a tool instance to the internal catalog.
     */
    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Check if a tool exists in the registry.
     */
    public function has(string $toolName): bool
    {
        return isset($this->tools[$toolName]);
    }

    /**
     * Retrieve a registered tool instance by name.
     *
     * @throws ToolNotFoundException
     */
    public function get(string $toolName): ToolInterface
    {
        if (! $this->has($toolName)) {
            throw new ToolNotFoundException($toolName);
        }

        return $this->tools[$toolName];
    }

    /**
     * Retrieve all registered tools.
     *
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Get all tool definitions permitted for an active agent role.
     *
     * @return array<int, ToolDefinition>
     */
    public function getToolsForRole(AgentRole $role): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            if ($this->authorize($tool->name(), $role)) {
                $definitions[] = $tool->definition();
            }
        }

        return $definitions;
    }

    /**
     * Get all tool schemas for an active agent role formatted for Anthropic Claude.
     *
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function getToolSchemasForRole(AgentRole $role): array
    {
        return array_map(
            fn (ToolDefinition $def) => $def->toLlmToolSchema(),
            $this->getToolsForRole($role)
        );
    }

    /**
     * Verify whether a given role holds the required capability for the requested tool.
     */
    public function authorize(string $toolName, AgentRole $role): bool
    {
        if (! $this->has($toolName)) {
            return false;
        }

        $tool = $this->get($toolName);
        $definition = $tool->definition();

        return $definition->isAllowedFor($role);
    }

    /**
     * Execute a tool by name with role authorization and argument validation.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws ToolNotFoundException
     * @throws UnauthorizedToolException
     * @throws InvalidToolArgumentException
     */
    public function execute(
        string $toolName,
        array $arguments,
        AgentRole $role,
        Incident $incident,
    ): array {
        // 1. Resolve tool by name
        $tool = $this->get($toolName);

        // 2. Authorize role permission
        if (! $this->authorize($toolName, $role)) {
            throw new UnauthorizedToolException(
                toolName: $toolName,
                role: $role,
                requiredPermission: $tool->requiredPermission(),
            );
        }

        // 3. Validate arguments against schema
        $this->validateArguments($tool, $arguments);

        // 4. Execute tool
        return $tool->execute($arguments, $incident);
    }

    /**
     * Validate input arguments against the tool parameters schema.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws InvalidToolArgumentException
     */
    protected function validateArguments(ToolInterface $tool, array $arguments): void
    {
        $schema = $tool->parametersSchema();
        $requiredFields = $schema['required'] ?? [];

        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $arguments) || $arguments[$field] === null || $arguments[$field] === '') {
                throw new InvalidToolArgumentException(
                    toolName: $tool->name(),
                    message: "Missing required parameter [{$field}].",
                );
            }
        }
    }
}
