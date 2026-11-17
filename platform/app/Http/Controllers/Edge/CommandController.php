<?php

namespace App\Http\Controllers\Edge;

use App\Events\IncidentChanged;
use App\Http\Controllers\Controller;
use App\Jobs\CheckForFire;
use App\Jobs\ReviewReportedSnapshot;
use App\Models\Camera;
use App\Models\CameraSetup;
use App\Models\DeterrenceCommand;
use App\Models\EdgeDevice;
use App\Models\SnapshotRequest;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Cameras\CameraWall;
use App\Services\Privacy\PrivacyMask;
use App\Services\Zones\ZoneSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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

        // A deterrence that waited more than a minute is useless: the moment passed.
        $deterrence = DeterrenceCommand::query()
            ->with('camera:id,frigate_name,siren')
            ->where('edge_device_id', $device->id)
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subMinute())
            ->get();
        DeterrenceCommand::whereIn('id', $deterrence->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);

        // K8 — cameras the technician asked to add: sent once, with their stream URL, then marked as sent.
        $setups = CameraSetup::where('edge_device_id', $device->id)->where('status', 'pending')->get();
        CameraSetup::whereIn('id', $setups->pluck('id'))->update(['status' => 'sent']);

        return response()->json([
            // Sent once per request: the scan takes a few seconds and the bridge polls every few.
            'discover' => $device->discovery_requested_at !== null && $device->discovery_requested_at->gt(now()->subMinutes(10))
                && Cache::add("edge-discover-{$device->id}-{$device->discovery_requested_at->timestamp}", true, 600),
            'motion_cameras' => Camera::where('edge_device_id', $device->id)->where('watch_motion', true)->pluck('frigate_name')->values(),
            'add_cameras' => $setups->map(fn (CameraSetup $setup): array => ['id' => $setup->id, 'name' => $setup->name, 'url' => $setup->stream_url])->values(),
            'snapshots' => $snapshots->map(fn (SnapshotRequest $snapshot): array => [
                'id' => $snapshot->id,
                'camera' => $snapshot->camera->frigate_name,
            ]),
            'deterrence' => $deterrence->map(fn (DeterrenceCommand $command): array => [
                'id' => $command->id,
                'camera' => $command->camera->frigate_name,
                'message' => $command->message,
                'siren' => $command->camera->siren,
            ]),
            'preview' => $wall->isWatched($device->site_id)
                ? ['cameras' => $device->cameras()->pluck('frigate_name'), 'interval' => CameraWall::PREVIEW_INTERVAL]
                : null,
            'zones' => $zones->payloadFor($device),
            // E6 — run the site's update command once; the new version shows in the next heartbeat.
            'update' => $device->update_requested_at !== null && $device->version !== config('vigilia.edge.latest_version'),
            'health_check' => [
                'interval' => CameraViewMonitor::CHECK_INTERVAL,
                'cameras' => $device->cameras()->pluck('frigate_name'),
            ],
        ]);
    }

    /**
     * The edge reports whether the speaker / siren actually played.
     */
    public function storeDeterrenceResult(Request $request, DeterrenceCommand $deterrenceCommand): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        abort_unless($deterrenceCommand->edge_device_id === $device->id, 404);

        $data = $request->validate(['ok' => ['required', 'boolean'], 'result' => ['nullable', 'string', 'max:255']]);
        $deterrenceCommand->forceFill(['status' => $data['ok'] ? 'done' : 'failed', 'done_at' => now(), 'result' => $data['result'] ?? null])->save();

        if (! $deterrenceCommand->incident) {
            return response()->json(['ok' => true]); // K11 — a speaker test: the result is shown on the camera.
        }

        $deterrenceCommand->incident->actions()->create([
            'type' => $data['ok'] ? 'deterrence_done' : 'deterrence_failed',
            'channel' => 'edge',
            'notes' => $data['result'] ?? null,
        ]);
        IncidentChanged::dispatch($deterrenceCommand->incident, 'deterrence');

        return response()->json(['ok' => true]);
    }

    public function storeSnapshot(Request $request, SnapshotRequest $snapshotRequest): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        abort_unless($snapshotRequest->edge_device_id === $device->id, 404);

        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120']]);

        $path = "sites/{$device->site_id}/incidents/{$snapshotRequest->incident_id}/live-{$snapshotRequest->camera_id}.jpg";
        Storage::disk(config('vigilia.media_disk'))->put($path, app(PrivacyMask::class)->apply($snapshotRequest->camera, $request->file('image')->get()));

        $snapshotRequest->update(['status' => 'fulfilled', 'snapshot_path' => $path, 'fulfilled_at' => now()]);

        ReviewReportedSnapshot::dispatch($snapshotRequest);

        return response()->json(['ok' => true]);
    }

    /**
     * Periodic frame used to detect a covered or turned camera.
     */
    public function storeHealthFrame(Request $request, CameraViewMonitor $monitor): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');

        $data = $request->validate([
            'camera' => ['required', 'string', 'max:100'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg', 'max:2048'],
        ]);

        $camera = Camera::where('edge_device_id', $device->id)->where('frigate_name', $data['camera'])->firstOrFail();
        $image = app(PrivacyMask::class)->apply($camera, $request->file('image')->get());
        $incident = $monitor->check($camera, $image);
        // M13 — cameras watched for smoke or fire: every fresh frame is looked at, not only on patrols.
        if ($camera->fire_watch && ($camera->fire_checked_at === null || $camera->fire_checked_at->lt(now()->subSeconds(50)))) {
            $camera->forceFill(['fire_checked_at' => now()])->save();
            CheckForFire::dispatch($camera, base64_encode($image))->onQueue('alerts');
        }

        return response()->json(['ok' => true, 'view_status' => $camera->fresh()->view_status->value, 'incident_id' => $incident?->id]);
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
