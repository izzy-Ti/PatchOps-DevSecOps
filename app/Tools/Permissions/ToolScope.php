<?php

namespace App\Tools\Permissions;

enum ToolScope: string
{
    case GITHUB_READ = 'github.read';
    case GITHUB_WRITE = 'github.write';
    case REPOSITORY_READ = 'repository.read';
    case REPOSITORY_WRITE = 'repository.write';
    case VULNERABILITY_READ = 'vulnerability.read';
    case SANDBOX_CREATE = 'sandbox.create';
    case SANDBOX_EXECUTE = 'sandbox.execute';
    case SANDBOX_DESTROY = 'sandbox.destroy';
}
