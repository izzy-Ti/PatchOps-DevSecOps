<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PullRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'incident_id',
        'patch_id',
        'repository',
        'branch',
        'base_branch',
        'commit_sha',
        'pr_number',
        'pr_url',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'pr_number' => 'integer',
        ];
    }

    /**
     * Get the incident associated with this pull request.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Get the patch artifact bound to this pull request.
     */
    public function patch(): BelongsTo
    {
        return $this->belongsTo(PatchArtifact::class, 'patch_id');
    }
}
