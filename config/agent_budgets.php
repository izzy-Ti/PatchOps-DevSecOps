<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Role Execution Budgets & Guardrails
    |--------------------------------------------------------------------------
    |
    | Defines operational quotas, rate limits, sandbox limits, and data transfer
    | ceilings per agent role to protect against runaway ReAct loops and resource exhaustion.
    |
    */

    'triage' => [
        'max_tool_calls' => (int) env('BUDGET_TRIAGE_MAX_TOOLS', 15),
        'max_execution_seconds' => (int) env('BUDGET_TRIAGE_TIMEOUT', 120),
        'max_sandboxes' => 0,
        'max_response_bytes' => 50 * 1024, // 50 KB
        'allowed_domains' => ['api.github.com', 'osv.dev'],
        'allow_sandbox' => false,
        'allow_write' => false,
    ],

    'reproduction' => [
        'max_tool_calls' => (int) env('BUDGET_REPRODUCE_MAX_TOOLS', 20),
        'max_execution_seconds' => (int) env('BUDGET_REPRODUCE_TIMEOUT', 600),
        'max_sandboxes' => 2,
        'max_response_bytes' => 100 * 1024, // 100 KB
        'allowed_domains' => [],
        'allow_sandbox' => true,
        'allow_write' => false,
    ],

    'patch' => [
        'max_tool_calls' => (int) env('BUDGET_PATCH_MAX_TOOLS', 25),
        'max_execution_seconds' => (int) env('BUDGET_PATCH_TIMEOUT', 300),
        'max_sandboxes' => 0,
        'max_response_bytes' => 100 * 1024, // 100 KB
        'allowed_domains' => ['api.github.com'],
        'allow_sandbox' => false,
        'allow_write' => true,
    ],

    'validation' => [
        'max_tool_calls' => (int) env('BUDGET_VALIDATE_MAX_TOOLS', 20),
        'max_execution_seconds' => (int) env('BUDGET_VALIDATE_TIMEOUT', 600),
        'max_sandboxes' => 2,
        'max_response_bytes' => 100 * 1024, // 100 KB
        'allowed_domains' => [],
        'allow_sandbox' => true,
        'allow_write' => false,
    ],

];
