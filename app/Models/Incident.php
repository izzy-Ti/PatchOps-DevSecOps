<?php

namespace App\Models;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Exceptions\InvalidIncidentStatusTransitionException;
use App\Services\AuditLogger;
use App\Services\Incident\IncidentStateMachine;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable([
    'vulnerability_id',
    'correlation_id',
    'incident_number',
    'title',
    'description',
    'severity',
    'priority',
    'status',
    'patch_iterations',
    'repository',
    'environment',
    'root_cause',
    'escalation_reason',
    'assigned_agent',
    'metadata',
    'user_id',
    'resolved_at',
    'remediated_at',
])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'severity' => VulnerabilitySeverity::MEDIUM,
        'priority' => IncidentPriority::MEDIUM,
        'status' => IncidentStatus::RECEIVED,
        'environment' => 'sandbox',
        'patch_iterations' => 1,
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->correlation_id ??= AuditLogger::resolveCorrelationId();
            $model->incident_number ??= 'INC-'.strtoupper(Str::random(8));
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity' => VulnerabilitySeverity::class,
            'priority' => IncidentPriority::class,
            'status' => IncidentStatus::class,
            'patch_iterations' => 'integer',
            'metadata' => 'array',
            'resolved_at' => 'datetime',
            'remediated_at' => 'datetime',
        ];
    }

    /**
     * Get the current count of patch attempts.
     */
    public function getPatchAttempts(): int
    {
        return (int) ($this->metadata['patch_attempts'] ?? 0);
    }

    /**
     * Increment the patch attempt count and persist to metadata.
     */
    public function incrementPatchAttempts(): int
    {
        $current = $this->getPatchAttempts() + 1;
        $this->metadata = array_merge($this->metadata ?? [], [
            'patch_attempts' => $current,
        ]);
        $this->save();

        return $current;
    }

    /**
     * Get the latest validation failure feedback for the repair loop.
     */
    public function getLatestValidationFeedback(): ?string
    {
        return $this->metadata['last_validation_feedback'] ?? null;
    }

    /**
     * Transition the incident to a new status via the dedicated State Machine service.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidIncidentStatusTransitionException
     */
    public function transitionTo(
        IncidentStatus $targetStatus,
        ?string $reason = null,
        string $actorType = 'system',
        ?string $actorId = null,
        array $metadata = [],
    ): Incident {
        return app(IncidentStateMachine::class)->transition(
            incident: $this,
            targetStatus: $targetStatus,
            reason: $reason,
            actorType: $actorType,
            actorId: $actorId,
            metadata: $metadata,
        );
    }

    /**
     * Scope a query to only include active (non-terminal) incidents.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            IncidentStatus::RESOLVED,
            IncidentStatus::CLOSED,
            IncidentStatus::FAILED,
        ]);
    }

    /**
     * Scope a query to only include incidents awaiting approval.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::AWAITING_APPROVAL);
    }

    /**
     * Scope a query to only include failed incidents.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::FAILED);
    }

    /**
     * Scope a query to only include resolved incidents.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::RESOLVED);
    }

    /**
     * Scope a query to only include received incidents.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::RECEIVED);
    }

    /**
     * Scope a query to only include triaging incidents.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTriaging(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::TRIAGING);
    }

    /**
     * Scope a query to only include closed incidents.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', IncidentStatus::CLOSED);
    }

    /**
     * Get the history of status transitions for the incident.
     *
     * @return HasMany<IncidentTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(IncidentTransition::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get the vulnerability associated with the incident.
     *
     * @return BelongsTo<Vulnerability, $this>
     */
    public function vulnerability(): BelongsTo
    {
        return $this->belongsTo(Vulnerability::class);
    }

    /**
     * Get the user who created or is assigned to the incident.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the execution history of agents for this incident.
     *
     * @return HasMany<AgentRun, $this>
     */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class)->orderBy('id', 'asc');
    }

    /**
     * Retrieve the model for a bound value by ID, incident_number, or correlation_id.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field) {
            return $this->where($field, $value)->first();
        }

        return $this->where('id', $value)
            ->orWhere('incident_number', $value)
            ->orWhere('correlation_id', $value)
            ->first();
    }

    /**
     * Get the normalized repository identifier for this incident.
     */
    public function getRepository(): string
    {
        return strtolower(trim((string) $this->repository));
    }

    /**
     * Get the tool executions telemetry for this incident.
     *
     * @return HasMany<ToolExecution, $this>
     */
    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class)->orderBy('id', 'asc');
    }

    /**
     * Get the structured evidence records for this incident.
     *
     * @return HasMany<IncidentEvidence, $this>
     */
    public function evidences(): HasMany
    {
        return $this->hasMany(IncidentEvidence::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest reproduction evidence for this incident.
     *
     * @return HasMany<IncidentEvidence, $this>
     */
    public function reproductionEvidence(): HasMany
    {
        return $this->hasMany(IncidentEvidence::class)->where('stage', 'reproduction')->orderBy('created_at', 'desc');
    }

    /**
     * Get the sandboxes provisioned for this incident.
     *
     * @return HasMany<Sandbox, $this>
     */
    public function sandboxes(): HasMany
    {
        return $this->hasMany(Sandbox::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get all patch artifacts generated for this incident.
     *
     * @return HasMany<PatchArtifact, $this>
     */
    public function patchArtifacts(): HasMany
    {
        return $this->hasMany(PatchArtifact::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest patch artifact for this incident.
     *
     * @return HasOne<PatchArtifact, $this>
     */
    public function latestPatchArtifact(): HasOne
    {
        return $this->hasOne(PatchArtifact::class)->latestOfMany();
    }

    /**
     * Get all quality gate runs for this incident.
     *
     * @return HasMany<QualityGateRun, $this>
     */
    public function qualityGateRuns(): HasMany
    {
        return $this->hasMany(QualityGateRun::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest quality gate run for this incident.
     *
     * @return HasOne<QualityGateRun, $this>
     */
    public function latestQualityGateRun(): HasOne
    {
        return $this->hasOne(QualityGateRun::class)->latestOfMany();
    }

    /**
     * Get all approvals recorded for this incident.
     *
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest approval recorded for this incident.
     *
     * @return HasOne<Approval, $this>
     */
    public function latestApproval(): HasOne
    {
        return $this->hasOne(Approval::class)->latestOfMany();
    }

    /**
     * Get all pull requests created for this incident.
     *
     * @return HasMany<PullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest pull request created for this incident.
     *
     * @return HasOne<PullRequest, $this>
     */
    public function latestPullRequest(): HasOne
    {
        return $this->hasOne(PullRequest::class)->latestOfMany();
    }

    /**
     * Accessor for CVE identifier.
     */
    public function getCveIdentifierAttribute(): string
    {
        return (string) ($this->vulnerability?->cve_id
            ?? $this->metadata['cve_id']
            ?? $this->metadata['cve']
            ?? $this->incident_number);
    }

    /**
     * Accessor for vulnerable commit SHA.
     */
    public function getVulnerableCommitShaAttribute(): string
    {
        return (string) ($this->metadata['commit_sha']
            ?? $this->metadata['vulnerable_commit_sha']
            ?? 'HEAD~1');
    }

    /**
     * Accessor for target base branch.
     */
    public function getBaseBranchAttribute(): string
    {
        return (string) ($this->metadata['base_branch'] ?? 'main');
    }

    /**
     * Accessor for patch iterations count.
     */
    public function getPatchIterationsAttribute(): int
    {
        return (int) ($this->attributes['patch_iterations'] ?? $this->getPatchAttempts());
    }

    /**
     * Increment the patch iterations count.
     */
    public function incrementPatchIterations(): int
    {
        $current = $this->patch_iterations + 1;
        $this->patch_iterations = $current;
        $this->save();

        return $current;
    }

    /**
     * Get all remediation runs for this incident.
     *
     * @return HasMany<RemediationRun, $this>
     */
    public function remediationRuns(): HasMany
    {
        return $this->hasMany(RemediationRun::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest remediation run for this incident.
     *
     * @return HasOne<RemediationRun, $this>
     */
    public function latestRemediationRun(): HasOne
    {
        return $this->hasOne(RemediationRun::class)->latestOfMany();
    }

    /**
     * Get all execution traces for this incident.
     *
     * @return HasMany<Trace, $this>
     */
    public function traces(): HasMany
    {
        return $this->hasMany(Trace::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest execution trace for this incident.
     *
     * @return HasOne<Trace, $this>
     */
    public function latestTrace(): HasOne
    {
        return $this->hasOne(Trace::class)->latestOfMany();
    }

    /**
     * Get all granular tool calls for this incident.
     *
     * @return HasMany<ToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get all immutable audit events for this incident.
     *
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class)->orderBy('created_at', 'asc');
    }
}
