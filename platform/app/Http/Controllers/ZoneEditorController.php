<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\Zone;
use App\Services\Cameras\CameraWall;
use App\Services\Zones\ZoneSync;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Draw the watched zones (fence, wall, gate) over a live frame of the camera.
 */
class ZoneEditorController extends Controller
{
    public function __invoke(Camera $camera, CameraWall $wall, ZoneSync $sync): Response
    {
        Gate::authorize('manage', $camera->site);

        $wall->markWatched($camera->site_id);
        $previewAt = $wall->previewAt($camera);
        $edge = $camera->edgeDevice;

        return Inertia::render('cameras/zones', [
            'camera' => [
                'id' => $camera->id,
                'name' => $camera->name,
                'sector' => $camera->sector,
                'site' => ['id' => $camera->site->id, 'name' => $camera->site->name],
                'preview_url' => $previewAt ? route('cameras.preview', ['camera' => $camera, 't' => $previewAt->timestamp]) : null,
            ],
            'zones' => $camera->zones()->orderBy('id')->get()->map(fn (Zone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'frigate_zone' => $zone->frigate_zone,
                'lot' => $zone->lot,
                'watched_labels' => $zone->watched_labels ?? [],
                'coordinates' => $zone->coordinates ?? [],
            ]),
            'synced' => $edge ? $sync->isSynced($edge) : null,
            'interval' => CameraWall::PREVIEW_INTERVAL,
        ]);
    }
}
