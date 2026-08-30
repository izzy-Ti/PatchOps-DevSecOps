<?php

namespace App\Tools\Enums;

enum AgentRole: string
{
    case TRIAGE = 'triage';
    case REPRODUCTION = 'reproduction';
    case PATCH = 'patch';
    case VALIDATION = 'validation';
}
