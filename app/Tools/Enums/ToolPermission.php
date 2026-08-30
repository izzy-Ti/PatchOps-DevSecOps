<?php

namespace App\Tools\Enums;

enum ToolPermission: string
{
    case GITHUB_READ = 'github.read';
    case GITHUB_WRITE = 'github.write';
    case VULNERABILITY_READ = 'vulnerability.read';
    case REPOSITORY_READ = 'repository.read';
    case REPOSITORY_WRITE = 'repository.write';
    case SANDBOX_PROVISION = 'sandbox.provision';
    case SANDBOX_EXECUTE = 'sandbox.execute';
    case SANDBOX_DESTROY = 'sandbox.destroy';
}
