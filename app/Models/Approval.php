<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'incident_id',
        'patch_id',
        'quality_gate_run_id',
        'approved_by',
        'status',
        'decision',
        'comment',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Get the incident associated with this approval.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Get the specific patch artifact bound to this approval.
     */
    public function patch(): BelongsTo
    {
        return $this->belongsTo(PatchArtifact::class, 'patch_id');
    }

    /**
     * Get the quality gate run that verified this patch prior to approval.
     */
    public function qualityGateRun(): BelongsTo
    {
        return $this->belongsTo(QualityGateRun::class, 'quality_gate_run_id');
    }
}
