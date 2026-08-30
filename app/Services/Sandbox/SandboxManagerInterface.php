<?php

namespace App\Services\Sandbox;

interface SandboxManagerInterface
{
    /**
     * Create an isolated sandbox workspace.
     */
    public function createWorkspace(string $workspaceId): string;

    /**
     * Write a file inside the isolated workspace.
     */
    public function writeFile(string $workspaceId, string $relativePath, string $content): void;

    /**
     * Run a command within the sandbox workspace.
     */
    public function runCommand(string $workspaceId, string $command, int $timeout = 120): ProcessResult;

    /**
     * Safely destroy and purge the sandbox workspace and any allocated containers.
     */
    public function cleanup(string $workspaceId): void;
}
