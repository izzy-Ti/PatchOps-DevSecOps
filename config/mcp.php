<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model Context Protocol (MCP) & GitHub Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Centralized settings for official MCP servers, JSON-RPC transports,
    | credentials, and security policies governing external tool execution.
    |
    */

    'servers' => [
        'github' => [
            'enabled' => (bool) env('MCP_GITHUB_ENABLED', true),
            'command' => env('MCP_GITHUB_COMMAND', 'npx -y @modelcontextprotocol/server-github'),
            'personal_access_token' => env('GITHUB_PERSONAL_ACCESS_TOKEN', env('GITHUB_TOKEN', '')),
            'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
            'server_url' => env('MCP_GITHUB_URL', ''),
            'transport' => env('MCP_GITHUB_TRANSPORT', 'stdio'),
            'timeout' => (int) env('MCP_GITHUB_TIMEOUT', 30),
            'max_file_size_bytes' => (int) env('MCP_MAX_FILE_BYTES', 50000),
        ],
    ],

    'security' => [
        'truncate_large_payloads' => true,
        'max_output_characters' => (int) env('MCP_MAX_OUTPUT_CHARS', 10000),
        'audit_invocations' => true,
    ],

];
