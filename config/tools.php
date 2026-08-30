<?php

use App\Tools\MCP\GitHub\CreatePullRequestTool;
use App\Tools\MCP\GitHub\GetFileTool;
use App\Tools\MCP\GitHub\GetRepositoryTool;
use App\Tools\MCP\Repository\InspectStructureTool;
use App\Tools\MCP\Repository\SearchCodeTool;
use App\Tools\MCP\Sandbox\CreateEnvironmentTool;
use App\Tools\MCP\Sandbox\DestroyEnvironmentTool;
use App\Tools\MCP\Sandbox\ExecuteCommandTool;
use App\Tools\MCP\Vulnerability\GetCveTool;
use App\Tools\MCP\Vulnerability\SearchVulnerabilityTool;
use App\Tools\Permissions\AgentRole;
use App\Tools\Permissions\ToolPermission;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Registered Tool Providers
    |--------------------------------------------------------------------------
    |
    | Autoloaded and registered into the ToolRegistry container during boot.
    |
    */

    'providers' => [
        GetRepositoryTool::class,
        GetFileTool::class,
        CreatePullRequestTool::class,
        GetCveTool::class,
        SearchVulnerabilityTool::class,
        InspectStructureTool::class,
        SearchCodeTool::class,
        CreateEnvironmentTool::class,
        ExecuteCommandTool::class,
        DestroyEnvironmentTool::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Role Permissions Matrix
    |--------------------------------------------------------------------------
    |
    | Maps each agent role to its granted tool capabilities.
    |
    */

    'role_permissions' => [
        AgentRole::TRIAGE->value => [
            ToolPermission::GITHUB_READ->value,
            ToolPermission::VULNERABILITY_READ->value,
            ToolPermission::REPOSITORY_READ->value,
        ],

        AgentRole::REPRODUCTION->value => [
            ToolPermission::GITHUB_READ->value,
            ToolPermission::VULNERABILITY_READ->value,
            ToolPermission::REPOSITORY_READ->value,
            ToolPermission::SANDBOX_PROVISION->value,
            ToolPermission::SANDBOX_EXECUTE->value,
            ToolPermission::SANDBOX_DESTROY->value,
        ],

        AgentRole::PATCH->value => [
            ToolPermission::GITHUB_READ->value,
            ToolPermission::VULNERABILITY_READ->value,
            ToolPermission::REPOSITORY_READ->value,
            ToolPermission::REPOSITORY_WRITE->value,
        ],

        AgentRole::VALIDATION->value => [
            ToolPermission::GITHUB_READ->value,
            ToolPermission::VULNERABILITY_READ->value,
            ToolPermission::REPOSITORY_READ->value,
            ToolPermission::SANDBOX_PROVISION->value,
            ToolPermission::SANDBOX_EXECUTE->value,
            ToolPermission::SANDBOX_DESTROY->value,
        ],

        AgentRole::POST_APPROVAL->value => [
            ToolPermission::GITHUB_READ->value,
            ToolPermission::GITHUB_WRITE->value,
        ],
    ],

];
