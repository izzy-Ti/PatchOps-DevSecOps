<?php

namespace App\Models;

use App\DTOs\ReproductionResultDTO;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'incident_id',
    'stage',
    'reproduced',
    'command',
    'exit_code',
    'stdout',
    'stderr',
    'duration_ms',
    'environment',
    'artifacts',
    'observations',
])]
class IncidentEvidence extends Model
{
    use HasFactory;

    protected $table = 'incident_evidences';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stage' => 'reproduction',
        'reproduced' => false,
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
            'reproduced' => 'boolean',
            'exit_code' => 'integer',
            'duration_ms' => 'float',
            'environment' => 'array',
            'artifacts' => 'array',
            'observations' => 'array',
        ];
    }

    /**
     * Get the incident this evidence belongs to.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Convert this model instance into a typed ReproductionResultDTO.
     */
    public function toReproductionDTO(): ReproductionResultDTO
    {
        return new ReproductionResultDTO(
            reproduced: $this->reproduced,
            exitCode: $this->exitCode,
            command: $this->command,
            stdout: (string) ($this->stdout ?? ''),
            stderr: (string) ($this->stderr ?? ''),
            durationMs: $this->duration_ms,
            environment: $this->environment ?? [],
            artifacts: $this->artifacts ?? [],
            observations: $this->observations ?? [],
        );
    }
}
