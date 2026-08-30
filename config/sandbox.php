<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disposable Containerized Sandbox Infrastructure Configuration
    |--------------------------------------------------------------------------
    |
    | Hardened Docker runtime configurations, cgroup ceilings, execution
    | timeouts, and security flags for ephemeral reproduction and validation.
    |
    */

    'docker_bin' => env('DOCKER_BINARY', 'docker'),

    'limits' => [
        'cpu' => env('SANDBOX_CPU_LIMIT', '2.0'),
        'memory' => env('SANDBOX_MEMORY_LIMIT', '2g'),
        'memory_swap' => env('SANDBOX_MEMORY_SWAP_LIMIT', '2g'),
        'timeout_seconds' => (int) env('SANDBOX_TIMEOUT_SECONDS', 600), // 10 minutes
        'tmpfs_size' => env('SANDBOX_TMPFS_SIZE', '512m'),
        'pids_limit' => (int) env('SANDBOX_PIDS_LIMIT', 100),
        'default_network' => env('SANDBOX_DEFAULT_NETWORK', 'none'), // Isolated network
        'max_output_bytes' => (int) env('SANDBOX_MAX_OUTPUT_BYTES', 50000),
    ],

    'security' => [
        'user' => env('SANDBOX_USER', '1000:1000'),
        'no_new_privileges' => true,
        'cap_drop_all' => true,
        'read_only_root' => (bool) env('SANDBOX_READ_ONLY_ROOT', true),
        'tmpfs_flags' => 'rw,noexec,nosuid',
    ],

    'timeouts' => [
        'default_execution' => (int) env('SANDBOX_TIMEOUT_SECONDS', 600),
        'container_stop' => (int) env('SANDBOX_STOP_TIMEOUT', 5),
    ],

    'runtimes' => [
        'node' => env('SANDBOX_IMAGE_NODE', 'node:20-alpine'),
        'php' => env('SANDBOX_IMAGE_PHP', 'php:8.4-cli-alpine'),
        'python' => env('SANDBOX_IMAGE_PYTHON', 'python:3.11-alpine'),
        'go' => env('SANDBOX_IMAGE_GO', 'golang:1.22-alpine'),
        'ruby' => env('SANDBOX_IMAGE_RUBY', 'ruby:3.2-alpine'),
    ],

    'storage_path' => storage_path('app/sandboxes'),

];
