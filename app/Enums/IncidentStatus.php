<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case RECEIVED = 'received';
    case TRIAGING = 'triaging';
    case PRIORITIZED = 'prioritized';
    case REPRODUCING = 'reproducing';
    case REPRODUCED = 'reproduced';
    case TRIAGED_NOT_REPRODUCIBLE = 'triaged_not_reproducible';
    case PATCHING = 'patching';
    case VALIDATING = 'validating';
    case AWAITING_APPROVAL = 'awaiting_approval';
    case PR_CREATED = 'pr_created';
    case CI_RUNNING = 'ci_running';
    case VERIFIED = 'verified';
    case RESOLVED = 'resolved';
    case FAILED = 'failed';
    case ESCALATED = 'escalated';
    case CLOSED = 'closed';

    /**
     * Get the allowed target transitions from the current status.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::RECEIVED => [
                self::TRIAGING,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::TRIAGING => [
                self::PRIORITIZED,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::PRIORITIZED => [
                self::REPRODUCING,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::REPRODUCING => [
                self::REPRODUCED,
                self::TRIAGED_NOT_REPRODUCIBLE,
                self::PRIORITIZED,
                self::FAILED,
                self::ESCALATED,
                self::CLOSED,
            ],
            self::TRIAGED_NOT_REPRODUCIBLE => [
                self::PRIORITIZED,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::REPRODUCED => [
                self::PATCHING,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::PATCHING => [
                self::VALIDATING,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::VALIDATING => [
                self::AWAITING_APPROVAL,
                self::PATCHING,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::AWAITING_APPROVAL => [
                self::PR_CREATED,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::PR_CREATED => [
                self::CI_RUNNING,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::CI_RUNNING => [
                self::VERIFIED,
                self::FAILED,
                self::ESCALATED,
                self::CLOSED,
            ],
            self::VERIFIED => [
                self::RESOLVED,
                self::ESCALATED,
                self::FAILED,
                self::CLOSED,
            ],
            self::RESOLVED => [
                self::CLOSED,
            ],
            self::ESCALATED => [
                self::TRIAGING,
                self::REPRODUCING,
                self::PATCHING,
                self::RESOLVED,
                self::FAILED,
                self::CLOSED,
            ],
            self::FAILED => [
                self::TRIAGING,
                self::CLOSED,
            ],
            self::CLOSED => [],
        };
    }

    /**
     * Check if transitioning to the given target status is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Determine if this status represents a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::RESOLVED, self::CLOSED, self::FAILED, self::TRIAGED_NOT_REPRODUCIBLE], true);
    }

    /**
     * Determine if this status requires human attention or approval.
     */
    public function isAwaitingHuman(): bool
    {
        return in_array($this, [self::AWAITING_APPROVAL, self::ESCALATED], true);
    }
}
