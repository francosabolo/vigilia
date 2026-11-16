<?php

namespace App\Models;

use App\Enums\DetectionStatus;
use Database\Factories\DetectionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'started_at', 'ended_at', 'snapshot_path', 'clip_path', 'status', 'ai_verdict', 'trace', 'incident_id',
        'ground_truth', 'ground_truth_source', 'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'zones' => 'array',
            'ai_verdict' => 'array',
            'ground_truth' => 'boolean',
            'trace' => 'array',
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

    /**
     * @return HasMany<ShadowVerdict, $this>
     */
    public function shadowVerdicts(): HasMany
    {
        return $this->hasMany(ShadowVerdict::class);
    }
}
