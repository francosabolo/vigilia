<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShadowVerdict extends Model
{
    protected $fillable = ['detection_event_id', 'challenger', 'model', 'prompt', 'champion_alerted', 'alerted', 'is_threat', 'confidence', 'description', 'input_tokens', 'output_tokens', 'cost_usd', 'latency_ms'];

    protected function casts(): array
    {
        return [
            'champion_alerted' => 'boolean',
            'alerted' => 'boolean',
            'is_threat' => 'boolean',
            'confidence' => 'float',
            'cost_usd' => 'float',
        ];
    }

    /**
     * @return BelongsTo<DetectionEvent, $this>
     */
    public function detectionEvent(): BelongsTo
    {
        return $this->belongsTo(DetectionEvent::class);
    }
}
