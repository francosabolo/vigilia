<?php

namespace App\Http\Controllers\Edge;

use App\Events\DetectionEventReceived;
use App\Http\Controllers\Controller;
use App\Http\Requests\Edge\StoreDetectionEventRequest;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

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
            'snapshot_path' => $this->storeMedia($request->file('snapshot'), $directory, 'snapshot'),
            'clip_path' => $this->storeMedia($request->file('clip'), $directory, 'clip'),
            'frame_paths' => collect($request->file('frames', []))
                ->values()
                ->map(fn (UploadedFile $file, int $index) => $this->storeMedia($file, $directory, "frame_{$index}"))
                ->filter()
                ->values()
                ->all() ?: null,
        ]))->save();

        if ($isNew) {
            DetectionEventReceived::dispatch($event);
        }

        return response()->json(['id' => $event->id, 'created' => $isNew], $isNew ? 201 : 200);
    }

    private function storeMedia(?UploadedFile $file, string $directory, string $name): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->storeAs(
            $directory,
            $name.'.'.$file->extension(),
            ['disk' => config('seguria.media_disk')],
        ) ?: null;
    }
}
