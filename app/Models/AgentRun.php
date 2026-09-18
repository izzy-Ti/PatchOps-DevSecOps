<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'incident_id',
        'trace_id',
        'agent_type',
        'status',
        'attempt',
        'iteration',
        'model',
        'input_tokens',
        'output_tokens',
        'duration_ms',
        'input_context',
        'output',
        'error',
        'started_at',
        'completed_at',
        'duration',
        'correlation_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'iteration' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'input_context' => 'array',
            'output' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration' => 'float',
        ];
    }

    /**
     * Get the incident associated with this agent execution run.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function trace(): BelongsTo
    {
        return $this->belongsTo(Trace::class, 'trace_id');
    }

    /**
     * Get the tool executions performed during this agent run.
     *
     * @return HasMany<ToolExecution, $this>
     */
    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class)->orderBy('id', 'asc');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(ToolCall::class, 'agent_run_id');
    }
}
