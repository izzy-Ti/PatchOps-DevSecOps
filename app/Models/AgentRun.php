<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'agent_type',
        'status',
        'attempt',
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
}
