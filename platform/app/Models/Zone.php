<?php

namespace App\Models;

use App\Enums\ZoneKind;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory;

    protected $fillable = ['camera_id', 'name', 'kind', 'frigate_zone', 'coordinates', 'is_perimeter', 'lot', 'watched_labels'];

    protected function casts(): array
    {
        return [
            'is_perimeter' => 'boolean',
            'kind' => ZoneKind::class,
            'watched_labels' => 'array',
            'coordinates' => 'array',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['kind' => 'perimeter'];

    /**
     * Frigate's relative coordinates format: "x1,y1,x2,y2,...".
     */
    public function frigateCoordinates(): ?string
    {
        if (empty($this->coordinates)) {
            return null;
        }

        return collect($this->coordinates)
            ->map(fn (array $point): string => round($point[0], 4).','.round($point[1], 4))
            ->implode(',');
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function armingSchedules(): HasMany
    {
        return $this->hasMany(ArmingSchedule::class);
    }
}
