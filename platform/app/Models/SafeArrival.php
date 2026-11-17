<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F9 — a resident on their way in. arriving → arrived (they confirmed), or expired (no confirmation: the guard is told).
 */
class SafeArrival extends Model
{
    protected $fillable = ['site_id', 'user_id', 'status', 'expected_by', 'arrived_at', 'alerted_at'];

    protected function casts(): array
    {
        return ['expected_by' => 'datetime', 'arrived_at' => 'datetime', 'alerted_at' => 'datetime'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'resident' => $this->resident ? ['id' => $this->resident->id, 'name' => $this->resident->name, 'lot' => $this->resident->lot, 'phone' => $this->resident->phone] : null,
            'expected_by' => $this->expected_by->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'started_at' => $this->created_at->toIso8601String(),
        ];
    }
}
