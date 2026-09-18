<?php

namespace App\Enums;

enum RemediationStatus: string
{
    case PR_CREATED = 'PR_CREATED';
    case CI_RUNNING = 'CI_RUNNING';
    case CI_FAILED = 'CI_FAILED';
    case CI_PASSED = 'CI_PASSED';
    case DEPLOYING = 'DEPLOYING';
    case DEPLOYED = 'DEPLOYED';
    case VERIFYING = 'VERIFYING';
    case REMEDIATED = 'REMEDIATED';
    case FAILED = 'FAILED';
    case ESCALATED = 'ESCALATED';
    case ROLLED_BACK = 'ROLLED_BACK';

    public function isTerminal(): bool
    {
        return in_array($this, [self::REMEDIATED, self::FAILED, self::ESCALATED, self::ROLLED_BACK], true);
    }
}
