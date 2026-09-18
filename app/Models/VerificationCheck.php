<?php

namespace App\Models;

use App\Enums\VerificationCheckType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationCheck extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'remediation_run_id',
        'check_type',
        'status',
        'target_endpoint',
        'exit_code',
        'response_code',
        'duration_ms',
        'evidence',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'check_type' => VerificationCheckType::class,
            'exit_code' => 'integer',
            'response_code' => 'integer',
            'duration_ms' => 'integer',
            'evidence' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function remediationRun(): BelongsTo
    {
        return $this->belongsTo(RemediationRun::class, 'remediation_run_id');
    }
}
