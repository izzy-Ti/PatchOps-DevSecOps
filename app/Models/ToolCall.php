<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ToolCall extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'agent_run_id',
        'incident_id',
        'trace_id',
        'tool_name',
        'server_name',
        'permission_scope',
        'risk_level',
        'arguments',
        'result',
        'status',
        'exit_code',
        'duration_ms',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= 'call_'.Str::ulid();
            $model->created_at ??= now();
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(Trace::class, 'trace_id');
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
