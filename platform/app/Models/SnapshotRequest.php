<?php

namespace App\Models;

use Database\Factories\SnapshotRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A live frame the platform asks an edge box for (e.g. when a resident reports something).
 * The bridge polls /api/edge/commands, grabs Frigate's latest frame and uploads it.
 */
class SnapshotRequest extends Model
{
    /** @use HasFactory<SnapshotRequestFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['edge_device_id', 'camera_id', 'incident_id', 'status', 'snapshot_path', 'sent_at', 'fulfilled_at'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function edgeDevice(): BelongsTo
    {
        return $this->belongsTo(EdgeDevice::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class)->withTrashed();
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
