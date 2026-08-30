<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sandbox Security Boundary & Capability Denylist Policy
    |--------------------------------------------------------------------------
    |
    | Defines the strict boundary policies between agent sandbox operations
    | and host infrastructure. Explicitly blocks host-level escapes, Docker
    | socket mounts, production database access, and privileged operations.
    |
    */

    'allowed_sandbox_tools' => [
        'sandbox.create',
        'sandbox.create_environment',
        'sandbox.execute',
        'sandbox.read_output',
        'sandbox.destroy',
        'sandbox.destroy_environment',
    ],

    'forbidden_capabilities' => [
        'host.execute',
        'host.filesystem',
        'docker.socket',
        'production.database',
        'production.shell',
        'system.exec',
        'system.process',
    ],

    'forbidden_path_patterns' => [
        '/var/run/docker.sock',
        '/etc/shadow',
        '/etc/passwd',
        '/proc',
        '/sys',
        '/dev',
        '/root',
        '/.env',
        '/storage/oauth',
    ],

    'forbidden_command_patterns' => [
        '/\b(docker|dockerd|podman|containerd|crictl)\b/i',
        '/\b(sudo|su|pkexec|doas)\b/i',
        '/\b(chroot|nsenter|unshare)\b/i',
        '/\bmount\b/i',
        '/\bumount\b/i',
        '/\/var\/run\/docker\.sock/i',
        '/\/proc\/sys/i',
        '/\/sys\/kernel/i',
        '/\b(nc|ncat|netcat)\s+.*-e\b/i',
        '/\b(bash|sh|zsh)\s+-i\b/i',
    ],

];
