<?php

namespace App\Enums;

enum AgentTool: string
{
    // GitHub Tools
    case GITHUB_GET_REPOSITORY = 'github.get_repository';
    case GITHUB_GET_FILE = 'github.get_file';
    case GITHUB_GET_DEPENDENCY_MANIFEST = 'github.get_dependency_manifest';
    case GITHUB_GET_PULL_REQUEST = 'github.get_pull_request';
    case GITHUB_CREATE_PULL_REQUEST = 'github.create_pull_request';

    // Vulnerability Tools
    case VULN_GET_CVE = 'vulnerability.get_cve';
    case VULN_GET_ADVISORY = 'vulnerability.get_advisory';
    case VULN_SEARCH = 'vulnerability.search_vulnerability';

    // Repository Tools
    case REPO_INSPECT_STRUCTURE = 'repository.inspect_structure';
    case REPO_READ_FILE = 'repository.read_file';
    case REPO_SEARCH_CODE = 'repository.search_code';
    case REPO_INSPECT_DEPENDENCIES = 'repository.inspect_dependencies';
    case REPO_MODIFY = 'repository.modify_repository';

    // Sandbox Tools
    case SANDBOX_CREATE_ENVIRONMENT = 'sandbox.create_environment';
    case SANDBOX_EXECUTE = 'sandbox.execute';
    case SANDBOX_COLLECT_OUTPUT = 'sandbox.collect_output';
    case SANDBOX_DESTROY_ENVIRONMENT = 'sandbox.destroy_environment';
}
