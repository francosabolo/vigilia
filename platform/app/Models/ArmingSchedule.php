<?php

namespace App\Models;

use Database\Factories\ArmingScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmingSchedule extends Model
{
    /** @use HasFactory<ArmingScheduleFactory> */
    use HasFactory;

    /** days: ISO weekday numbers (1 = Monday ... 7 = Sunday). */
    protected $fillable = ['zone_id', 'days', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['days' => 'array'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
