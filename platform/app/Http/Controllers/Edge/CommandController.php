<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Jobs\ReviewReportedSnapshot;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\SnapshotRequest;
use App\Services\Cameras\CameraWall;
use App\Services\Zones\ZoneSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pull-based command channel: edge boxes sit behind NAT, so they poll for work instead of being called.
 */
class CommandController extends Controller
{
    public function index(Request $request, CameraWall $wall, ZoneSync $zones): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');

        $snapshots = SnapshotRequest::query()
            ->with('camera:id,frigate_name')
            ->where('edge_device_id', $device->id)
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subMinutes(5))
            ->get();

        SnapshotRequest::whereIn('id', $snapshots->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);

        return response()->json([
            'snapshots' => $snapshots->map(fn (SnapshotRequest $snapshot): array => [
                'id' => $snapshot->id,
                'camera' => $snapshot->camera->frigate_name,
            ]),
            'preview' => $wall->isWatched($device->site_id)
                ? ['cameras' => $device->cameras()->pluck('frigate_name'), 'interval' => CameraWall::PREVIEW_INTERVAL]
                : null,
            'zones' => $zones->payloadFor($device),
        ]);
    }

    public function storeSnapshot(Request $request, SnapshotRequest $snapshotRequest): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        abort_unless($snapshotRequest->edge_device_id === $device->id, 404);

        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120']]);

        $path = $request->file('image')->storeAs(
            "sites/{$device->site_id}/incidents/{$snapshotRequest->incident_id}",
            "live-{$snapshotRequest->camera_id}.".$request->file('image')->extension(),
            ['disk' => config('seguria.media_disk')],
        );

        $snapshotRequest->update(['status' => 'fulfilled', 'snapshot_path' => $path, 'fulfilled_at' => now()]);

        ReviewReportedSnapshot::dispatch($snapshotRequest);

        return response()->json(['ok' => true]);
    }

    public function storePreview(Request $request, CameraWall $wall): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');

        $data = $request->validate([
            'camera' => ['required', 'string', 'max:100'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg', 'max:2048'],
        ]);

        $camera = Camera::where('edge_device_id', $device->id)->where('frigate_name', $data['camera'])->firstOrFail();
        $wall->storePreview($camera, $request->file('image'));

        return response()->json(['ok' => true]);
    }
}
