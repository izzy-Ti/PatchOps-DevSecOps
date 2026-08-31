<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'incident_id',
    'sandbox_id',
    'agent_run_id',
    'correlation_id',
    'command',
    'exit_code',
    'stdout',
    'stderr',
    'duration_ms',
])]
class SandboxExecution extends Model
{
    use HasFactory;

    protected $table = 'sandbox_executions';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'exit_code' => 0,
        'duration_ms' => 0.0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'float',
        ];
    }

    /**
     * Get the incident associated with this execution.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
