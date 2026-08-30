<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case OPEN = 'open';
    case TRIAGING = 'triaging';
    case REPRODUCING = 'reproducing';
    case PATCHING = 'patching';
    case AWAITING_APPROVAL = 'awaiting_approval';
    case RESOLVED = 'resolved';
    case FAILED = 'failed';
}
