<?php

namespace App\Services\Media;

use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\SnapshotRequest;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Images of people are personal data (Ley 25.326): keep them only as long as they are useful.
 * Deletes snapshots and clips older than the retention window, except the evidence of incidents that are
 * still open or were confirmed as intrusions.
 */
class MediaRetention
{
    /** Shared demo images are not personal data and are referenced by many rows. */
    private const SHARED_PREFIX = 'demo-library/';

    /**
     * @return array{files: int, detections: int, incidents: int, live_frames: int}
     */
    public function prune(?Carbon $now = null): array
    {
        $now ??= now();
        $cutoff = $now->copy()->subDays(config('vigilia.retention_days'));
        $disk = Storage::disk(config('vigilia.media_disk'));
        $stats = ['files' => 0, 'detections' => 0, 'incidents' => 0, 'live_frames' => 0];

        $kept = Incident::query()
            ->where(fn ($query) => $query->where('status', '!=', IncidentStatus::Resolved)->orWhere('resolution', IncidentResolution::Confirmed))
            ->select('id');

        DetectionEvent::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNotNull('snapshot_path')->orWhereNotNull('clip_path')->orWhereNotNull('frame_paths'))
            ->where(fn ($query) => $query->whereNull('incident_id')->orWhereNotIn('incident_id', $kept))
            ->chunkById(500, function ($events) use ($disk, &$stats) {
                foreach ($events as $event) {
                    $stats['files'] += $this->delete($disk, $event->snapshot_path, $event->clip_path, ...($event->frame_paths ?? []));
                    $event->forceFill(['snapshot_path' => null, 'clip_path' => null, 'frame_paths' => null])->saveQuietly();
                    $stats['detections']++;
                }
            });

        Incident::query()
            ->where('detected_at', '<', $cutoff)
            ->where('status', IncidentStatus::Resolved)
            ->where(fn ($query) => $query->whereNull('resolution')->orWhere('resolution', '!=', IncidentResolution::Confirmed))
            ->where(fn ($query) => $query->whereNotNull('snapshot_path')->orWhereNotNull('clip_path'))
            ->chunkById(500, function ($incidents) use ($disk, &$stats) {
                foreach ($incidents as $incident) {
                    $stats['files'] += $this->delete($disk, $incident->snapshot_path, $incident->clip_path);
                    $incident->forceFill(['snapshot_path' => null, 'clip_path' => null])->saveQuietly();
                    $stats['incidents']++;
                }
            });

        SnapshotRequest::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('snapshot_path')
            ->chunkById(500, function ($requests) use ($disk, &$stats) {
                foreach ($requests as $request) {
                    $stats['files'] += $this->delete($disk, $request->snapshot_path);
                    $request->forceFill(['snapshot_path' => null])->saveQuietly();
                    $stats['live_frames']++;
                }
            });

        // Evaluation scratch files are never needed after the run.
        foreach ($disk->files('eval-tmp') as $file) {
            if ($disk->lastModified($file) < $now->copy()->subDay()->timestamp) {
                $stats['files'] += (int) $disk->delete($file);
            }
        }

        Log::info('Media retention', $stats + ['retention_days' => config('vigilia.retention_days'), 'cutoff' => $cutoff->toIso8601String()]);

        return $stats;
    }

    private function delete(Filesystem $disk, ?string ...$paths): int
    {
        $deleted = 0;
        foreach (array_filter($paths) as $path) {
            if (! str_starts_with($path, self::SHARED_PREFIX) && $disk->exists($path)) {
                $deleted += (int) $disk->delete($path);
            }
        }

        return $deleted;
    }
}
