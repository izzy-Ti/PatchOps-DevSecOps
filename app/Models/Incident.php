<?php

namespace App\Models;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Services\AuditLogger;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'status' => IncidentStatus::OPEN,
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
}
