<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Edge\HeartbeatRequest;
use App\Models\Camera;
use App\Models\EdgeDevice;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    public function __invoke(HeartbeatRequest $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $data = $request->validated();

        $device->update(array_filter([
            'version' => $data['version'] ?? null,
            'zones_version' => $data['zones_version'] ?? null,
        ]));

        foreach ($data['cameras'] ?? [] as $cam) {
            $camera = Camera::withTrashed()->firstOrNew(
                ['edge_device_id' => $device->id, 'frigate_name' => $cam['name']],
                ['site_id' => $device->site_id, 'name' => $cam['name']],
            );
            // Removed from the console on purpose: the edge still sees it until the technician takes it out of Frigate.
            if ($camera->trashed()) {
                continue;
            }
            $camera->is_online = $cam['online'];
            if ($cam['online']) {
                $camera->last_seen_at = now();
            }
            $camera->save();
        }

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()]);
    }
}
