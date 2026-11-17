<?php

namespace App\Http\Controllers\Edge;

use App\Enums\DetectionStatus;
use App\Enums\ZoneKind;
use App\Events\DetectionEventReceived;
use App\Http\Controllers\Controller;
use App\Http\Requests\Edge\StoreDetectionEventRequest;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Services\Privacy\PrivacyMask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DetectionEventController extends Controller
{
    public function store(StoreDetectionEventRequest $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $data = $request->validated();

        $camera = Camera::withTrashed()->firstOrCreate(
            ['edge_device_id' => $device->id, 'frigate_name' => $data['camera']],
            ['site_id' => $device->site_id, 'name' => $data['camera']],
        );
        if ($camera->trashed()) {
            return response()->json(['ok' => true, 'ignored' => 'camera_removed']);
        }

        // M7 — motion without a person from a camera watched for it: it counts for the high-risk zones of the camera.
        if ($data['label'] === 'motion') {
            if (! $camera->watch_motion) {
                return response()->json(['ok' => true, 'ignored' => 'motion_not_watched']);
            }
            $data['zones'] = $camera->zones()->get()->filter(fn ($zone) => $zone->kind->isAccessPoint() || $zone->kind === ZoneKind::Perimeter)->pluck('frigate_zone')->values()->all();
        }

        // The bridge retries on network failures: the Frigate event id makes ingest idempotent.
        $event = DetectionEvent::firstOrNew([
            'edge_device_id' => $device->id,
            'external_id' => $data['id'],
        ]);
        $isNew = ! $event->exists;

        $event->fill([
            'camera_id' => $camera->id,
            'label' => $data['label'],
            'score' => $data['score'] ?? null,
            'zones' => $data['zones'] ?? [],
            'started_at' => Carbon::createFromTimestamp((float) $data['start_time']),
            'ended_at' => isset($data['end_time']) ? Carbon::createFromTimestamp((float) $data['end_time']) : null,
        ])->save();

        $directory = "sites/{$device->site_id}/events/{$event->id}";
        $event->fill(array_filter([
            'snapshot_path' => $this->storeMedia($request->file('snapshot'), $directory, 'snapshot', $camera),
            'clip_path' => $this->storeMedia($request->file('clip'), $directory, 'clip'),
            'frame_paths' => collect($request->file('frames', []))
                ->values()
                ->map(fn (UploadedFile $file, int $index) => $this->storeMedia($file, $directory, "frame_{$index}", $camera))
                ->filter()
                ->values()
                ->all() ?: null,
        ]))->save();

        if ($isNew) {
            DetectionEventReceived::dispatch($event);
        } elseif ($event->status === DetectionStatus::Dismissed && $event->ended_at && in_array($event->label, ['car', 'truck', 'motorcycle'], true)
            && $event->started_at->diffInMinutes($event->ended_at, absolute: true) >= (int) config('seguria.tracking.stopped_vehicle_minutes', 5)) {
            // A6 — the early send was dismissed; the end of the event shows the vehicle stayed: look again.
            $event->forceFill(['status' => DetectionStatus::Pending])->save();
            DetectionEventReceived::dispatch($event);
        }

        return response()->json(['id' => $event->id, 'created' => $isNew], $isNew ? 201 : 200);
    }

    private function storeMedia(?UploadedFile $file, string $directory, string $name, ?Camera $camera = null): ?string
    {
        if (! $file) {
            return null;
        }

        // J2 — privacy areas are blacked out before the image is stored or seen by the AI. Clips are masked on the edge.
        if ($camera && in_array(strtolower($file->extension()), ['jpg', 'jpeg', 'png'], true)) {
            $path = "{$directory}/{$name}.jpg";
            Storage::disk(config('seguria.media_disk'))->put($path, app(PrivacyMask::class)->apply($camera, $file->get()));

            return $path;
        }

        return $file->storeAs(
            $directory,
            $name.'.'.$file->extension(),
            ['disk' => config('seguria.media_disk')],
        ) ?: null;
    }
}
