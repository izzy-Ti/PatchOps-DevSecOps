<?php

namespace App\Models;

use App\Exceptions\Observability\ImmutableAuditEventException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AuditEvent extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'incident_id',
        'actor_type',
        'actor_id',
        'action',
        'resource_type',
        'resource_id',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= 'audit_'.Str::ulid();
            $model->created_at ??= now();
        });

        static::updating(function (): void {
            throw new ImmutableAuditEventException('Audit events cannot be modified. Record a new sequential event instead.');
        });

        static::deleting(function (): void {
            throw new ImmutableAuditEventException('Audit events cannot be deleted. The audit trail is permanently append-only.');
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
