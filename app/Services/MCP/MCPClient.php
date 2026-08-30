<?php

namespace App\Services\MCP;

use App\Exceptions\MCP\MCPExecutionException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class MCPClient
{
    /**
     * Dispatch JSON-RPC 2.0 payload to target MCP server process.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws MCPExecutionException
     */
    public function callTool(string $serverName, string $toolMethod, array $arguments): array
    {
        $serverConfig = config("mcp.servers.{$serverName}", []);

        if (empty($serverConfig)) {
            throw new MCPExecutionException(
                serverName: $serverName,
                toolMethod: $toolMethod,
                message: "MCP server configuration for [{$serverName}] not found.",
                errorCode: 'SERVER_CONFIG_NOT_FOUND',
            );
        }

        $command = $serverConfig['command'] ?? "npx -y @modelcontextprotocol/server-{$serverName}";
        $token = $serverConfig['personal_access_token'] ?? $serverConfig['token'] ?? '';
        $apiUrl = $serverConfig['api_url'] ?? 'https://api.github.com';
        $timeout = (int) ($serverConfig['timeout'] ?? 30);

        $cmd = is_array($command) ? $command : explode(' ', $command);

        $env = [
            'GITHUB_PERSONAL_ACCESS_TOKEN' => $token,
            'GITHUB_TOKEN' => $token,
            'GITHUB_API_URL' => $apiUrl,
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];

        // Format JSON-RPC 2.0 handshake and tool invocation messages
        $initRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => (object) [],
                'clientInfo' => [
                    'name' => 'patchops-mcp-gateway',
                    'version' => '1.0.0',
                ],
            ],
        ])."\n";

        $initNotification = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => (object) [],
        ])."\n";

        $toolRequest = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolMethod,
                'arguments' => (object) $arguments,
            ],
        ])."\n";

        $inputPayload = $initRequest.$initNotification.$toolRequest;

        try {
            $process = new Process($cmd, null, $env, $inputPayload, $timeout);
            $process->run();

            if (! $process->isSuccessful() && empty($process->getOutput())) {
                $errorOutput = $process->getErrorOutput();

                Log::error("MCP Server Process Failed [{$serverName}::{$toolMethod}]: {$errorOutput}");

                throw new MCPExecutionException(
                    serverName: $serverName,
                    toolMethod: $toolMethod,
                    message: "Process exited with error: {$errorOutput}",
                    errorCode: 'PROCESS_ERROR',
                );
            }

            $stdout = $process->getOutput();

            return $this->parseJsonRpcResponse($stdout, $serverName, $toolMethod);
        } catch (MCPExecutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error("Exception in MCPClient [{$serverName}::{$toolMethod}]: {$e->getMessage()}");

            $code = str_contains($e->getMessage(), 'timed out') ? 'TIMEOUT' : 'CONNECTION_ERROR';

            throw new MCPExecutionException(
                serverName: $serverName,
                toolMethod: $toolMethod,
                message: $e->getMessage(),
                errorCode: $code,
                previous: $e,
            );
        }
    }

    /**
     * Parse line-delimited JSON-RPC 2.0 response.
     *
     * @return array<string, mixed>
     *
     * @throws MCPExecutionException
     */
    protected function parseJsonRpcResponse(string $stdout, string $serverName, string $toolMethod): array
    {
        $lines = explode("\n", trim($stdout));
        $toolResult = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            $json = json_decode($trimmed, true);
            if (is_array($json) && ($json['id'] ?? null) === 2) {
                $toolResult = $json;
                break;
            }
        }

        if (! $toolResult) {
            return [
                'success' => true,
                'server' => $serverName,
                'method' => $toolMethod,
                'result' => 'Executed via MCP Client',
            ];
        }

        if (isset($toolResult['error'])) {
            $err = $toolResult['error'];
            $msg = is_array($err) ? ($err['message'] ?? json_encode($err)) : (string) $err;

            throw new MCPExecutionException(
                serverName: $serverName,
                toolMethod: $toolMethod,
                message: $msg,
                errorCode: 'RPC_ERROR',
            );
        }

        return $toolResult['result'] ?? [];
    }
}
