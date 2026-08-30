<?php

namespace App\Tools\Permissions;

enum AgentRole: string
{
    case TRIAGE = 'triage';
    case REPRODUCTION = 'reproduction';
    case PATCH = 'patch';
    case VALIDATION = 'validation';
    case POST_APPROVAL = 'post_approval';
}
