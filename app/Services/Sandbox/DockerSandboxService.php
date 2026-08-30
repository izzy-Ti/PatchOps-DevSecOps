<?php

namespace App\Services\Sandbox;

use App\Exceptions\SandboxTimeoutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

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
     * Run a command within the sandbox workspace with strict execution & idle timeouts.
     *
     * @throws SandboxTimeoutException
     */
    public function runCommand(string $workspaceId, string $command, ?int $timeout = null): ProcessResult
    {
        $workspacePath = $this->createWorkspace($workspaceId);
        $timeoutLimit = $timeout ?? (int) config('patchops.timeouts.sandbox_command', 180);
        $idleLimit = (int) config('patchops.timeouts.sandbox_idle', 60);

        $startTime = microtime(true);

        try {
            $process = Process::fromShellCommandline($command, $workspacePath);
            $process->setTimeout($timeoutLimit);
            if ($idleLimit > 0) {
                $process->setIdleTimeout($idleLimit);
            }

            $process->run();

            $executionTime = microtime(true) - $startTime;

            return new ProcessResult(
                success: $process->isSuccessful(),
                exitCode: $process->getExitCode() ?? 1,
                stdout: $process->getOutput(),
                stderr: $process->getErrorOutput(),
                executionTimeSeconds: round($executionTime, 3),
            );
        } catch (ProcessTimedOutException $e) {
            $executionTime = microtime(true) - $startTime;

            Log::error("Sandbox process timed out after {$timeoutLimit}s in workspace [{$workspaceId}]: {$command}", [
                'workspace_id' => $workspaceId,
                'command' => $command,
                'timeout' => $timeoutLimit,
                'elapsed_seconds' => round($executionTime, 3),
            ]);

            throw new SandboxTimeoutException(
                "Sandbox process exceeded timeout limit of {$timeoutLimit}s for command [{$command}]: {$e->getMessage()}",
                0,
                $e
            );
        }
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
