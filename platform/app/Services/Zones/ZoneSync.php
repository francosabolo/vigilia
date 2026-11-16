<?php

namespace App\Services\Zones;

use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Zone;

/**
 * The console is the source of truth for zones. Each edge pulls the zones of its cameras through the command
 * channel and, when the version changes, the bridge writes them into Frigate's config and restarts it.
 */
class ZoneSync
{
    /**
     * @return array{version: string, cameras: array<string, array<string, array{coordinates: string, objects: array<int, string>}>>}
     */
    public function payloadFor(EdgeDevice $device): array
    {
        $cameras = $device->cameras()->with('zones')->orderBy('id')->get()
            ->mapWithKeys(fn (Camera $camera): array => [
                $camera->frigate_name => $camera->zones
                    ->filter(fn (Zone $zone): bool => $zone->frigateCoordinates() !== null)
                    ->sortBy('frigate_zone')
                    ->mapWithKeys(fn (Zone $zone): array => [
                        $zone->frigate_zone => [
                            'coordinates' => $zone->frigateCoordinates(),
                            'objects' => array_values($zone->watched_labels ?: ['person', 'car', 'motorcycle']),
                        ],
                    ])
                    ->all(),
            ])
            // Cameras without drawn zones are left untouched on the edge.
            ->filter()
            ->all();

        return ['version' => sha1(json_encode($cameras)), 'cameras' => $cameras];
    }

    public function isSynced(EdgeDevice $device): bool
    {
        return $device->zones_version === $this->payloadFor($device)['version'];
    }
}
