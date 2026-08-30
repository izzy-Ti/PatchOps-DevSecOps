<?php

use App\Enums\AgentRole;
use App\Enums\AgentTool;

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Least-Privilege Permission Matrix
    |--------------------------------------------------------------------------
    |
    | Strict role-to-tool mappings enforcing isolation boundaries across agents.
    | Prohibits arbitrary tool execution, write mutations, or out-of-band actions.
    |
    */

    'matrix' => [
        AgentRole::TRIAGE->value => [
            AgentTool::GITHUB_GET_REPOSITORY->value,
            AgentTool::GITHUB_GET_FILE->value,
            AgentTool::GITHUB_GET_DEPENDENCY_MANIFEST->value,
            AgentTool::GITHUB_GET_PULL_REQUEST->value,
            AgentTool::VULN_GET_CVE->value,
            AgentTool::VULN_GET_ADVISORY->value,
            AgentTool::VULN_SEARCH->value,
            AgentTool::REPO_INSPECT_STRUCTURE->value,
            AgentTool::REPO_READ_FILE->value,
            AgentTool::REPO_SEARCH_CODE->value,
            AgentTool::REPO_INSPECT_DEPENDENCIES->value,
        ],

        AgentRole::REPRODUCTION->value => [
            AgentTool::GITHUB_GET_REPOSITORY->value,
            AgentTool::GITHUB_GET_FILE->value,
            AgentTool::GITHUB_GET_DEPENDENCY_MANIFEST->value,
            AgentTool::VULN_GET_CVE->value,
            AgentTool::VULN_GET_ADVISORY->value,
            AgentTool::VULN_SEARCH->value,
            AgentTool::REPO_INSPECT_STRUCTURE->value,
            AgentTool::REPO_READ_FILE->value,
            AgentTool::REPO_SEARCH_CODE->value,
            AgentTool::REPO_INSPECT_DEPENDENCIES->value,
            AgentTool::SANDBOX_CREATE_ENVIRONMENT->value,
            AgentTool::SANDBOX_EXECUTE->value,
            AgentTool::SANDBOX_COLLECT_OUTPUT->value,
            AgentTool::SANDBOX_DESTROY_ENVIRONMENT->value,
        ],

        AgentRole::PATCH->value => [
            AgentTool::GITHUB_GET_REPOSITORY->value,
            AgentTool::GITHUB_GET_FILE->value,
            AgentTool::GITHUB_GET_DEPENDENCY_MANIFEST->value,
            AgentTool::GITHUB_GET_PULL_REQUEST->value,
            AgentTool::VULN_GET_CVE->value,
            AgentTool::VULN_GET_ADVISORY->value,
            AgentTool::REPO_INSPECT_STRUCTURE->value,
            AgentTool::REPO_READ_FILE->value,
            AgentTool::REPO_SEARCH_CODE->value,
            AgentTool::REPO_INSPECT_DEPENDENCIES->value,
            AgentTool::REPO_MODIFY->value,
        ],

        AgentRole::VALIDATION->value => [
            AgentTool::GITHUB_GET_REPOSITORY->value,
            AgentTool::GITHUB_GET_FILE->value,
            AgentTool::GITHUB_GET_DEPENDENCY_MANIFEST->value,
            AgentTool::GITHUB_GET_PULL_REQUEST->value,
            AgentTool::VULN_GET_CVE->value,
            AgentTool::VULN_GET_ADVISORY->value,
            AgentTool::REPO_INSPECT_STRUCTURE->value,
            AgentTool::REPO_READ_FILE->value,
            AgentTool::REPO_SEARCH_CODE->value,
            AgentTool::REPO_INSPECT_DEPENDENCIES->value,
            AgentTool::SANDBOX_CREATE_ENVIRONMENT->value,
            AgentTool::SANDBOX_EXECUTE->value,
            AgentTool::SANDBOX_COLLECT_OUTPUT->value,
            AgentTool::SANDBOX_DESTROY_ENVIRONMENT->value,
        ],

        AgentRole::POST_APPROVAL->value => [
            AgentTool::GITHUB_GET_REPOSITORY->value,
            AgentTool::GITHUB_GET_FILE->value,
            AgentTool::GITHUB_GET_PULL_REQUEST->value,
            AgentTool::GITHUB_CREATE_PULL_REQUEST->value,
        ],
    ],

];
