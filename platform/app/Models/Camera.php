<?php

namespace App\Models;

use Database\Factories\CameraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Camera extends Model
{
    /** @use HasFactory<CameraFactory> */
    use HasFactory;

    protected $fillable = ['site_id', 'edge_device_id', 'name', 'frigate_name', 'sector', 'is_online', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function edgeDevice(): BelongsTo
    {
        return $this->belongsTo(EdgeDevice::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }
}
