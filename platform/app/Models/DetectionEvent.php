<?php

namespace App\Models;

use App\Enums\DetectionStatus;
use Database\Factories\DetectionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetectionEvent extends Model
{
    /** @use HasFactory<DetectionEventFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'edge_device_id', 'camera_id', 'external_id', 'label', 'score', 'zones',
        'started_at', 'ended_at', 'snapshot_path', 'clip_path', 'status', 'ai_verdict', 'incident_id',
    ];

    protected function casts(): array
    {
        return [
            'zones' => 'array',
            'ai_verdict' => 'array',
            'score' => 'float',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'status' => DetectionStatus::class,
        ];
    }

    public function edgeDevice(): BelongsTo
    {
        return $this->belongsTo(EdgeDevice::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
