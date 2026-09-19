<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_TRIAGE_MODEL', 'claude-3-5-sonnet-latest'),
        'version' => '2023-06-01',
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-pro-latest'),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.1),
        'max_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 8192),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'github' => [
        'app_id' => env('GITHUB_APP_ID'),
        'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'token' => env('GITHUB_TOKEN', env('GITHUB_PERSONAL_ACCESS_TOKEN')),
        'repository' => env('GITHUB_REPOSITORY', 'izzy-Ti/PatchOps-DevSecOps'),
    ],

    'security' => [
        'secrets_redaction_enabled' => (bool) env('SECRETS_REDACTION_ENABLED', true),
        'quality_gate_max_iterations' => (int) env('QUALITY_GATE_MAX_ITERATIONS', 3),
    ],

];
