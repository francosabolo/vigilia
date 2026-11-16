<?php

namespace App\Http\Controllers;

use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\SnapshotRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentMediaController extends Controller
{
    /**
     * Snapshot of a single detection (activity feed and decision trail). Protected by a signed URL.
     */
    public function detection(Request $request, DetectionEvent $detectionEvent): StreamedResponse
    {
        $disk = Storage::disk(config('seguria.media_disk'));
        $path = $request->has('frame')
            ? ($detectionEvent->frame_paths ?? [])[(int) $request->query('frame')] ?? null
            : $detectionEvent->snapshot_path;
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * Live frame requested from the edge after a resident report. Protected by a signed URL.
     */
    public function liveFrame(SnapshotRequest $snapshotRequest): StreamedResponse
    {
        $disk = Storage::disk(config('seguria.media_disk'));
        abort_unless($snapshotRequest->snapshot_path && $disk->exists($snapshotRequest->snapshot_path), 404);

        return $disk->response($snapshotRequest->snapshot_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * Protected by a temporary signed URL (see Incident::mediaUrl).
     */
    public function __invoke(Incident $incident, string $kind): StreamedResponse
    {
        $path = match ($kind) {
            'snapshot' => $incident->snapshot_path,
            'clip' => $incident->clip_path,
            'before' => $incident->actions()->where('type', 'tamper')->latest('id')->value('meta')['before_path'] ?? null,
            default => null,
        };

        $disk = Storage::disk(config('seguria.media_disk'));
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }
}
