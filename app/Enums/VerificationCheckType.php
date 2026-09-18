<?php

namespace App\Enums;

enum VerificationCheckType: string
{
    case CI_PIPELINE = 'ci_pipeline';
    case DEPLOYMENT_STATUS = 'deployment_status';
    case APPLICATION_HEALTH = 'application_health';
    case API_AVAILABILITY = 'api_availability';
    case SECURITY_SCAN = 'security_scan';
    case EXPLOIT_REVERIFICATION = 'exploit_reverification';
}
