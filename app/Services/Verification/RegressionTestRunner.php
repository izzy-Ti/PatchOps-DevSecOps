<?php

namespace App\Services\Verification;

use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use Throwable;

class RegressionTestRunner
{
    public function __construct(
        protected ?MCPToolGateway $gateway = null,
    ) {
        $this->gateway ??= app(MCPToolGateway::class);
    }

    /**
     * Run regression test commands and project test suites inside the sandbox workspace.
     *
     * @param  array<int, string>  $testSuites
     * @return array{passed: bool, checks: array<int, array<string, mixed>>, failed_tests: array<int, string>, stdout: string, stderr: string}
     */
    public function runTests(
        Incident $incident,
        string $sandboxId,
        ?string $regressionCommand = null,
        array $testSuites = [],
        ?string $agentRunId = null,
    ): array {
        $checks = [];
        $failedTests = [];
        $combinedStdout = '';
        $combinedStderr = '';
        $overallPassed = true;

        $commandsToRun = [];

        if (! empty($regressionCommand)) {
            $commandsToRun[] = [
                'name' => 'Synthesized Vulnerability Regression Test',
                'command' => $regressionCommand,
            ];
        }

        foreach ($testSuites as $suiteCmd) {
            if ($suiteCmd !== $regressionCommand) {
                $commandsToRun[] = [
                    'name' => 'Project Regression Test Suite',
                    'command' => $suiteCmd,
                ];
            }
        }

        if (empty($commandsToRun)) {
            $commandsToRun[] = [
                'name' => 'Default Test Suite',
                'command' => 'npm test',
            ];
        }

        foreach ($commandsToRun as $entry) {
            $cmdName = $entry['name'];
            $command = $entry['command'];

            try {
                $execResult = $this->gateway->execute(
                    role: AgentRole::REVIEWER,
                    toolName: 'sandbox.execute',
                    arguments: [
                        'sandbox_id' => $sandboxId,
                        'command' => $command,
                        'timeout' => 300,
                    ],
                    context: $incident,
                    agentRunId: $agentRunId ? (int) $agentRunId : null,
                );

                $exitCode = (int) ($execResult['exit_code'] ?? 0);
                $stdout = (string) ($execResult['stdout'] ?? '');
                $stderr = (string) ($execResult['stderr'] ?? '');
                $passed = ($exitCode === 0);

                $combinedStdout .= "\n".$stdout;
                $combinedStderr .= "\n".$stderr;

                $checks[] = [
                    'check_name' => $cmdName,
                    'command' => $command,
                    'exit_code' => $exitCode,
                    'passed' => $passed,
                    'details' => $passed ? 'Executed successfully (exit code 0).' : "Failed with exit code {$exitCode}: {$stderr}",
                ];

                if (! $passed) {
                    $overallPassed = false;
                    $failedTests[] = "{$cmdName} ({$command})";
                }
            } catch (Throwable $e) {
                $overallPassed = false;
                $failedTests[] = "{$cmdName} execution exception: {$e->getMessage()}";
                $checks[] = [
                    'check_name' => $cmdName,
                    'command' => $command,
                    'exit_code' => 1,
                    'passed' => false,
                    'details' => "Execution exception: {$e->getMessage()}",
                ];
            }
        }

        return [
            'passed' => $overallPassed,
            'checks' => $checks,
            'failed_tests' => $failedTests,
            'stdout' => trim($combinedStdout),
            'stderr' => trim($combinedStderr),
        ];
    }
}
