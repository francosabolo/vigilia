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
        'ground_truth', 'ground_truth_source', 'reviewed_by', 'frame_paths', 'is_test', 'qa_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'qa_requested_at' => 'datetime',
            'zones' => 'array',
            'ai_verdict' => 'array',
            'ground_truth' => 'boolean',
            'frame_paths' => 'array',
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
        return $this->belongsTo(Camera::class)->withTrashed();
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

    /**
     * Whether the AI gets a sequence of frames (2 or more) instead of a single photo.
     */
    public function hasSequence(): bool
    {
        return count($this->frame_paths ?? []) >= 2;
    }
}
