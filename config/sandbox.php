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

    'security' => [
        'user' => env('SANDBOX_USER', '1000:1000'),
        'no_new_privileges' => true,
        'cap_drop_all' => true,
        'read_only_root' => (bool) env('SANDBOX_READ_ONLY_ROOT', true),
        'tmpfs_size' => env('SANDBOX_TMPFS_SIZE', '64m'),
        'network_mode' => env('SANDBOX_NETWORK', 'none'),
    ],

    'resources' => [
        'memory_limit' => env('SANDBOX_MEMORY_LIMIT', '512m'),
        'cpu_limit' => env('SANDBOX_CPU_LIMIT', '1.0'),
        'pids_limit' => (int) env('SANDBOX_PIDS_LIMIT', 100),
    ],

    'timeouts' => [
        'default_execution' => (int) env('SANDBOX_DEFAULT_TIMEOUT', 180),
        'max_execution' => (int) env('SANDBOX_MAX_TIMEOUT', 300),
        'container_stop' => (int) env('SANDBOX_STOP_TIMEOUT', 5),
    ],

    'limits' => [
        'max_output_bytes' => (int) env('SANDBOX_MAX_OUTPUT_BYTES', 50000),
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
