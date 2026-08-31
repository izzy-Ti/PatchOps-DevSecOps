<?php

namespace App\Services\MCP\Guards;

use App\Exceptions\MCP\InvalidSandboxIdentifierException;
use App\Exceptions\MCP\InvalidSandboxLifecycleStateException;
use App\Models\Incident;
use App\Models\ToolExecution;
use Illuminate\Support\Facades\Log;

class SandboxLifecycleGuard
{
    /**
     * Validate opaque sandbox identifier format and lifecycle state.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws InvalidSandboxIdentifierException
     * @throws InvalidSandboxLifecycleStateException
     */
    public function validate(Incident $incident, array $arguments, string $toolName): void
    {
        if (! str_starts_with($toolName, 'sandbox.')) {
            return;
        }

        // Creation tools do not have an existing sandbox_id to validate
        if ($toolName === 'sandbox.create_sandbox' || $toolName === 'sandbox.create_environment') {
            return;
        }

        $sandboxId = (string) ($arguments['sandbox_id'] ?? $arguments['workspace_id'] ?? '');

        if (empty($sandboxId)) {
            throw new InvalidSandboxIdentifierException('EMPTY_ID', $toolName);
        }

        // Validate that sandbox identifier is an opaque, safe prefixed string (reject raw 64-char/12-char container hashes)
        $isPrefixed = str_starts_with($sandboxId, 'sb_')
            || str_starts_with($sandboxId, 'sbx-')
            || str_starts_with($sandboxId, 'repro-')
            || str_starts_with($sandboxId, 'val-');

        $isRawDockerHash = preg_match('/^[a-f0-9]{12}$|^[a-f0-9]{64}$/i', $sandboxId);

        if (! $isPrefixed || $isRawDockerHash) {
            Log::warning("Blocked attempt to invoke tool [{$toolName}] with raw container hash [{$sandboxId}].");
            throw new InvalidSandboxIdentifierException($sandboxId, $toolName);
        }

        // Check if sandbox was already destroyed in tool_executions
        $isDestroyed = ToolExecution::where('incident_id', $incident->id)
            ->whereIn('tool_name', ['sandbox.destroy_sandbox', 'sandbox.destroy_environment'])
            ->where('status', 'success')
            ->where(function ($q) use ($sandboxId) {
                $q->whereJsonContains('arguments->sandbox_id', $sandboxId)
                    ->orWhereJsonContains('arguments->workspace_id', $sandboxId);
            })
            ->exists();

        if ($isDestroyed && ! in_array($toolName, ['sandbox.destroy_sandbox', 'sandbox.destroy_environment'], true)) {
            Log::warning("Blocked attempt to invoke [{$toolName}] on already DESTROYED sandbox [{$sandboxId}].");
            throw new InvalidSandboxLifecycleStateException($sandboxId, 'DESTROYED', $toolName);
        }
    }
}
