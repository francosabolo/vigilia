<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftHandover extends Model
{
    protected $fillable = ['site_id', 'user_id', 'period_start', 'period_end', 'summary', 'notes', 'open_incidents'];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'site' => $this->site?->name,
            'by' => $this->user?->name,
            'from' => $this->period_start->toIso8601String(),
            'to' => $this->period_end->toIso8601String(),
            'summary' => $this->summary,
            'notes' => $this->notes,
            'open_incidents' => $this->open_incidents,
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
