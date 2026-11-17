<?php

namespace App\Services\Quality;

use App\Enums\DetectionStatus;
use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Site;

/**
 * I7 — what the AI dismissed never reaches anyone, so its misses are invisible. Every week a random sample of the
 * dismissed detections of each property goes to a person; their answers give the miss rate with its sample size.
 */
class DismissedSample
{
    public const PER_SITE = 20;

    /**
     * @return int how many detections were sampled
     */
    public function sample(): int
    {
        $total = 0;

        foreach (Site::where('is_simulated', false)->pluck('id') as $siteId) {
            $ids = DetectionEvent::query()
                ->whereHas('camera', fn ($query) => $query->where('site_id', $siteId))
                ->where('status', DetectionStatus::Dismissed)
                ->where('is_test', false)
                ->whereNull('ground_truth')
                ->whereNull('qa_requested_at')
                ->whereNotNull('snapshot_path')
                ->where('started_at', '>=', now()->subWeek())
                ->inRandomOrder()
                ->limit(self::PER_SITE)
                ->pluck('id');

            DetectionEvent::whereIn('id', $ids)->update(['qa_requested_at' => now()]);
            $total += $ids->count();
        }

        return $total;
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<int, array<string, mixed>>
     */
    public function stats(array $siteIds, int $weeks = 8): array
    {
        $since = now()->subWeeks($weeks);

        return Site::whereIn('id', $siteIds)->orderBy('name')->get()->map(function (Site $site) use ($since): array {
            $reviewed = DetectionEvent::query()
                ->whereHas('camera', fn ($query) => $query->withTrashed()->where('site_id', $site->id))
                ->whereNotNull('qa_requested_at')->where('qa_requested_at', '>=', $since)
                ->where('ground_truth_source', 'qa')
                ->get(['ground_truth']);
            $closed = Incident::where('site_id', $site->id)->where('resolved_at', '>=', $since)
                ->where('source', '!=', IncidentSource::Equipment)
                ->whereIn('resolution', [IncidentResolution::Confirmed, IncidentResolution::FalseAlarm])->get(['resolution']);
            $missed = $reviewed->where('ground_truth', true)->count();
            // Real time: from the moment the edge saw it to the moment the guard was notified.
            $latencies = Incident::where('site_id', $site->id)->where('source', IncidentSource::Detection)->where('detected_at', '>=', now()->subWeek())
                ->whereNotNull('notified_at')->get(['detected_at', 'notified_at'])
                ->map(fn (Incident $incident) => $incident->detected_at->diffInSeconds($incident->notified_at, absolute: true))->sort()->values();
            $false = $closed->where('resolution', IncidentResolution::FalseAlarm)->count();

            return [
                'site' => $site->name,
                'reviewed' => $reviewed->count(),
                'missed' => $missed,
                'miss_rate' => $reviewed->isEmpty() ? null : round(100 * $missed / $reviewed->count(), 1),
                'closed' => $closed->count(),
                'false_alarms' => $false,
                'false_alarm_rate' => $closed->isEmpty() ? null : round(100 * $false / $closed->count(), 1),
                'alert_p50' => $latencies->isEmpty() ? null : (int) round($latencies->get((int) floor(0.5 * ($latencies->count() - 1)))),
                'alert_p95' => $latencies->isEmpty() ? null : (int) round($latencies->get((int) floor(0.95 * ($latencies->count() - 1)))),
                'alerts_measured' => $latencies->count(),
            ];
        })->all();
    }
}
