<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'incident_id',
    'sandbox_id',
    'runtime',
    'runtime_version',
    'repository',
    'commit_sha',
    'status',
    'expires_at',
    'destroyed_at',
])]
class Sandbox extends Model
{
    use HasFactory;

    protected $table = 'sandboxes';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'runtime' => 'node',
        'status' => 'initialized',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'destroyed_at' => 'datetime',
        ];
    }

    /**
     * The incident associated with this sandbox.
     *
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Scope query to active sandboxes.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['destroyed', 'expired']);
    }

    /**
     * Scope query to expired sandboxes past their hard expiration ceiling.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->active()->where('expires_at', '<=', now());
    }
}
