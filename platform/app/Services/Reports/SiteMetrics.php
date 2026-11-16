<?php

namespace App\Services\Reports;

use App\Enums\DetectionStatus;
use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Models\DetectionEvent;
use App\Models\Incident;
use Carbon\CarbonInterface;

/**
 * The numbers that sell the product: how much noise the AI removed and how fast the guards responded.
 */
class SiteMetrics
{
    /**
     * @param  array<int, int>  $siteIds
     * @return array{detections: int, filtered_by_ai: int, outside_schedule: int, incidents: int, confirmed: int, false_alarms: int, escalated: int, avg_ack_seconds: ?int, noise_reduction: ?float}
     */
    public function forSites(array $siteIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $detections = DetectionEvent::query()
            ->whereHas('camera', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereBetween('started_at', [$from, $to])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $incidents = Incident::query()->whereIn('site_id', $siteIds)->whereBetween('detected_at', [$from, $to])
            ->where('source', '!=', IncidentSource::Equipment);

        $ackSeconds = (clone $incidents)->whereNotNull('acknowledged_at')->get(['detected_at', 'acknowledged_at'])
            ->map(fn (Incident $incident): int => (int) $incident->detected_at->diffInSeconds($incident->acknowledged_at));

        $total = (int) $detections->sum();
        $filtered = (int) ($detections[DetectionStatus::Dismissed->value] ?? 0);
        $incidentCount = (clone $incidents)->count();

        return [
            'detections' => $total,
            'filtered_by_ai' => $filtered,
            'outside_schedule' => (int) ($detections[DetectionStatus::Ignored->value] ?? 0),
            'incidents' => $incidentCount,
            'confirmed' => (clone $incidents)->where('resolution', IncidentResolution::Confirmed)->count(),
            'false_alarms' => (clone $incidents)->where('resolution', IncidentResolution::FalseAlarm)->count(),
            'escalated' => (clone $incidents)->whereNotNull('escalated_at')->count(),
            'avg_ack_seconds' => $ackSeconds->isEmpty() ? null : (int) round($ackSeconds->avg()),
            'noise_reduction' => $total > 0 ? round(1 - $incidentCount / $total, 3) : null,
        ];
    }
}
