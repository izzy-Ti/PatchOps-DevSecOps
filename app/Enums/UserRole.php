<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case SECURITY = 'security';
    case DEVELOPER = 'developer';
    case VIEWER = 'viewer';
}
