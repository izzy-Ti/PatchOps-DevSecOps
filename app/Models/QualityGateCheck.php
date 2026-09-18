<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityGateCheck extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'quality_gate_run_id',
        'check_type',
        'status',
        'exit_code',
        'stdout',
        'stderr',
        'duration_ms',
        'is_critical',
        'failure_classification',
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'float',
            'is_critical' => 'boolean',
        ];
    }

    /**
     * Get the quality gate run that owns this check.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(QualityGateRun::class, 'quality_gate_run_id');
    }
}
