<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model Context Protocol (MCP) & GitHub Gateway Configuration
    |--------------------------------------------------------------------------
    |
    | Centralized settings for MCP servers, transports, credentials, and
    | security boundary policies governing external tool execution.
    |
    */

    'servers' => [
        'github' => [
            'enabled' => (bool) env('MCP_GITHUB_ENABLED', true),
            'token' => env('GITHUB_TOKEN', ''),
            'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
            'server_url' => env('MCP_GITHUB_URL', ''),
            'timeout' => (int) env('MCP_TIMEOUT', 30),
            'max_file_size_bytes' => (int) env('MCP_MAX_FILE_BYTES', 50000),
        ],
    ],

    'security' => [
        'truncate_large_payloads' => true,
        'max_output_characters' => (int) env('MCP_MAX_OUTPUT_CHARS', 10000),
        'audit_invocations' => true,
    ],

];
