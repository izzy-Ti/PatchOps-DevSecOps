<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PatchArtifact extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'incident_id',
        'iteration',
        'diff',
        'fix_summary',
        'files_changed',
        'tests_added',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'iteration' => 'integer',
            'files_changed' => 'array',
            'tests_added' => 'array',
        ];
    }

    /**
     * Get the incident that owns the patch artifact.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Get all quality gate runs for this patch artifact.
     */
    public function qualityGateRuns(): HasMany
    {
        return $this->hasMany(QualityGateRun::class, 'patch_id');
    }

    /**
     * Get the latest quality gate run for this patch artifact.
     */
    public function latestQualityGateRun(): HasOne
    {
        return $this->hasOne(QualityGateRun::class, 'patch_id')->latest('created_at');
    }

    /**
     * Get all approvals for this patch artifact.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'patch_id');
    }

    /**
     * Get the latest approval for this patch artifact.
     */
    public function latestApproval(): HasOne
    {
        return $this->hasOne(Approval::class, 'patch_id')->latest('created_at');
    }

    /**
     * Get pull requests associated with this patch.
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class, 'patch_id');
    }

    /**
     * Alias for diff to support unified_diff accessor.
     */
    public function getUnifiedDiffAttribute(): string
    {
        return (string) $this->diff;
    }

    /**
     * Alias for fix_summary to support summary accessor.
     */
    public function getSummaryAttribute(): ?string
    {
        return $this->fix_summary;
    }
}
