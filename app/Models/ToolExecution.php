<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'agent_run_id',
        'tool_name',
        'arguments',
        'result',
        'status',
        'permission',
        'risk_level',
        'correlation_id',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'float',
    ];

    /**
     * Get the incident associated with this tool execution.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Get the agent run during which this tool was invoked.
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
