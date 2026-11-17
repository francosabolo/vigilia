<?php

namespace App\Models;

use App\Enums\CameraViewStatus;
use Database\Factories\CameraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Camera extends Model
{
    /** @use HasFactory<CameraFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['view_status' => 'ok', 'view_anomaly_count' => 0, 'heading' => 0, 'fov' => 90, 'range_m' => 40];

    protected $fillable = ['site_id', 'edge_device_id', 'name', 'frigate_name', 'sector', 'lat', 'lng', 'heading', 'fov', 'range_m', 'view_status', 'view_shift_deg', 'view_anomaly_count', 'view_checked_at', 'is_online', 'last_seen_at', 'offline_since', 'is_guard_post', 'is_checkpoint', 'is_access', 'ai_notes'];

    protected function casts(): array
    {
        return [
            'is_guard_post' => 'boolean',
            'is_checkpoint' => 'boolean',
            'is_access' => 'boolean',
            'is_online' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'heading' => 'integer',
            'fov' => 'integer',
            'range_m' => 'integer',
            'view_status' => CameraViewStatus::class,
            'view_shift_deg' => 'integer',
            'view_checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'offline_since' => 'datetime',
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

    /**
     * I6 — adds a lesson from a closed false alarm, keeping the newest when the notes get long.
     */
    public function learn(string $lesson): void
    {
        $notes = trim(($this->ai_notes ? $this->ai_notes."\n" : '').'- '.trim($lesson));
        $this->update(['ai_notes' => mb_strlen($notes) > 1200 ? mb_substr($notes, -1200) : $notes]);
    }
}
