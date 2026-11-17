<?php

namespace App\Services\Tracking;

use App\Enums\IncidentSource;
use App\Models\Camera;
use App\Models\Incident;
use App\Services\Cameras\CameraWall;
use App\Support\Geo;

/**
 * A10 — follow the intruder: where they were seen last, which way they were heading and which cameras to look at
 * now. The next cameras are the nearest ones, favouring those ahead in the direction of movement.
 */
class IntruderFollow
{
    public function __construct(private readonly CameraWall $wall) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Incident $incident): ?array
    {
        if ($incident->source !== IncidentSource::Detection || ! $incident->status->isOpen()) {
            return null;
        }

        $events = $incident->detectionEvents()->with('camera')->orderBy('started_at')->get();
        $last = $events->last();
        $current = $last?->camera ?? $incident->camera;
        if (! $current) {
            return null;
        }

        // While someone follows the incident, the edge keeps the live previews coming.
        $this->wall->markWatched($incident->site_id);

        $previous = $events->reverse()->first(fn ($event) => $event->camera_id !== $current->id)?->camera;
        $heading = $previous ? self::bearing($previous, $current) : null;

        $next = Camera::where('site_id', $incident->site_id)->where('id', '!=', $current->id)->where('is_online', true)->get()
            ->map(function (Camera $camera) use ($current, $heading): array {
                $distance = Geo::distanceMeters($current, $camera);
                $toCamera = self::bearing($current, $camera);
                $ahead = $heading !== null && $toCamera !== null && abs((($toCamera - $heading + 540) % 360) - 180) <= 60;

                return ['camera' => $camera, 'distance' => $distance, 'ahead' => $ahead, 'score' => ($distance ?? 10_000) * ($ahead ? 0.4 : 1)];
            })
            ->sortBy('score')
            ->take(3)
            ->values()
            ->map(fn (array $item): array => $this->camera($item['camera']) + ['distance_m' => $item['distance'], 'ahead' => $item['ahead']]);

        return [
            'current' => $this->camera($current) + ['seen_at' => ($last?->started_at ?? $incident->detected_at)->toIso8601String()],
            'heading' => $heading !== null ? self::compass($heading) : null,
            'look_for' => $incident->description,
            'next' => $next->all(),
            'sightings' => $events->unique('camera_id')->count(),
        ];
    }

    /**
     * @return array{id: int, name: string, sector: string|null, preview_url: string|null}
     */
    private function camera(Camera $camera): array
    {
        $at = $this->wall->previewAt($camera);

        return [
            'id' => $camera->id,
            'name' => $camera->name,
            'sector' => $camera->sector,
            'preview_url' => $at ? route('cameras.preview', ['camera' => $camera, 't' => $at->timestamp]) : null,
        ];
    }

    public static function bearing(Camera $from, Camera $to): ?int
    {
        if ($from->lat === null || $to->lat === null) {
            return null;
        }
        $y = sin(deg2rad($to->lng - $from->lng)) * cos(deg2rad($to->lat));
        $x = cos(deg2rad($from->lat)) * sin(deg2rad($to->lat)) - sin(deg2rad($from->lat)) * cos(deg2rad($to->lat)) * cos(deg2rad($to->lng - $from->lng));

        return ((int) round(rad2deg(atan2($y, $x))) + 360) % 360;
    }

    private static function compass(int $bearing): string
    {
        return ['el norte', 'el noreste', 'el este', 'el sureste', 'el sur', 'el suroeste', 'el oeste', 'el noroeste'][(int) round($bearing / 45) % 8];
    }
}
