<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\SnapshotRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentMediaController extends Controller
{
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
