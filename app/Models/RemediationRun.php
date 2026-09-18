<?php

namespace App\Models;

use App\Enums\RemediationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemediationRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'incident_id',
        'patch_id',
        'pull_request_id',
        'ci_run_id',
        'deployment_id',
        'status',
        'health_status',
        'security_status',
        'environment',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RemediationStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function patch(): BelongsTo
    {
        return $this->belongsTo(PatchArtifact::class, 'patch_id');
    }

    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class, 'pull_request_id');
    }

    public function verificationChecks(): HasMany
    {
        return $this->hasMany(VerificationCheck::class, 'remediation_run_id');
    }
}
