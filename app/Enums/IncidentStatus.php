<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case RECEIVED = 'received';
    case TRIAGING = 'triaging';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';
}
