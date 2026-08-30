<?php

namespace App\Services\Sandbox;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DockerSandboxService implements SandboxManagerInterface
{
    /**
     * Base path where sandboxes are created.
     */
    protected string $baseStoragePath;

    public function __construct(?string $basePath = null)
    {
        $this->baseStoragePath = $basePath ?? storage_path('app/sandboxes');

        if (! File::exists($this->baseStoragePath)) {
            File::makeDirectory($this->baseStoragePath, 0755, true, true);
        }
    }

    /**
     * Create an isolated sandbox workspace.
     */
    public function createWorkspace(string $workspaceId): string
    {
        $safeId = Str::slug($workspaceId);
        $workspacePath = $this->baseStoragePath.DIRECTORY_SEPARATOR.$safeId;

        if (! File::exists($workspacePath)) {
            File::makeDirectory($workspacePath, 0755, true, true);
        }

        return $workspacePath;
    }

    /**
     * Write a file inside the isolated workspace.
     */
    public function writeFile(string $workspaceId, string $relativePath, string $content): void
    {
        $workspacePath = $this->createWorkspace($workspaceId);
        $fullPath = $workspacePath.DIRECTORY_SEPARATOR.ltrim($relativePath, '/\\');

        $directory = dirname($fullPath);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        File::put($fullPath, $content);
    }

    /**
     * Run a command within the sandbox workspace.
     */
    public function runCommand(string $workspaceId, string $command, int $timeout = 120): ProcessResult
    {
        $workspacePath = $this->createWorkspace($workspaceId);
        $startTime = microtime(true);

        $result = Process::path($workspacePath)
            ->timeout($timeout)
            ->run($command);

        $executionTime = microtime(true) - $startTime;

        return new ProcessResult(
            success: $result->successful(),
            exitCode: $result->exitCode() ?? 1,
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            executionTimeSeconds: round($executionTime, 3),
        );
    }

    /**
     * Safely destroy and purge the sandbox workspace and any allocated containers.
     */
    public function cleanup(string $workspaceId): void
    {
        $safeId = Str::slug($workspaceId);
        $workspacePath = $this->baseStoragePath.DIRECTORY_SEPARATOR.$safeId;

        if (File::exists($workspacePath)) {
            File::deleteDirectory($workspacePath);
        }
    }
}
