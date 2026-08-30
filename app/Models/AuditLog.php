<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

#[Fillable([
    'correlation_id',
    'actor_type',
    'actor_id',
    'event',
    'auditable_type',
    'auditable_id',
    'payload',
    'ip_address',
    'created_at',
])]
class AuditLog extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->created_at ??= now();
        });

        static::updating(function (): void {
            throw new RuntimeException('Audit logs are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Audit logs are immutable and cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the parent auditable model (polymorphic relation).
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include audit logs for a given correlation ID.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCorrelation(Builder $query, string $id): Builder
    {
        return $query->where('correlation_id', $id);
    }
}
