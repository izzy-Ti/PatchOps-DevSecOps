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
    'repository',
    'environment',
    'root_cause',
    'assigned_agent',
    'metadata',
    'user_id',
    'resolved_at',
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
            'metadata' => 'array',
            'resolved_at' => 'datetime',
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
}
