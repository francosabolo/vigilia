<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guard seen by a camera: walking past a round checkpoint (from a detection) or at the post (from a check).
 * present=false records an empty guard post.
 */
class GuardSighting extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_id', 'camera_id', 'source', 'present', 'description', 'seen_at'];

    protected function casts(): array
    {
        return ['present' => 'boolean', 'seen_at' => 'datetime'];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class)->withTrashed();
    }
}
