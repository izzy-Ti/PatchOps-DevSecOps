<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityGateRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'incident_id',
        'patch_id',
        'iteration',
        'passed',
        'total_checks',
        'passed_checks',
        'failed_checks',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'iteration' => 'integer',
            'passed' => 'boolean',
            'total_checks' => 'integer',
            'passed_checks' => 'integer',
            'failed_checks' => 'integer',
            'duration_ms' => 'float',
        ];
    }

    /**
     * Get the incident associated with this quality gate run.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Get the patch artifact evaluated by this quality gate run.
     */
    public function patch(): BelongsTo
    {
        return $this->belongsTo(PatchArtifact::class, 'patch_id');
    }

    /**
     * Get all check results recorded in this run.
     */
    public function checks(): HasMany
    {
        return $this->hasMany(QualityGateCheck::class, 'quality_gate_run_id');
    }
}
