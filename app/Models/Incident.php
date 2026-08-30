<?php

namespace App\Models;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'correlation_id',
    'title',
    'description',
    'severity',
    'status',
    'metadata',
    'user_id',
])]
class Incident extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->correlation_id ??= AuditLogger::resolveCorrelationId();
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
