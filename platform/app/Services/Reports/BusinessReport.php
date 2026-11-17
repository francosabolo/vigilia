<?php

namespace App\Services\Reports;

use App\Enums\IncidentSource;
use App\Models\Client;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Patrol;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * H4 SLA compliance per client, H5 what to bill per client and the AI consumption, H6 estimated margin per property.
 * Costs are estimates and labelled as such: AI calls times an average cost, operator time at the configured rate.
 */
class BusinessReport
{
    public function __construct(private readonly SiteMetrics $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $sites = Site::where('organization_id', $organization->id)->where('is_simulated', false)->withCount('cameras')->with('client')->orderBy('name')->get();
        $costPerCall = (float) config('vigilia.ai.estimated_cost_per_call_usd');
        $rate = (float) ($organization->usd_rate ?? 0);

        $rows = $sites->map(function (Site $site) use ($organization, $from, $to, $costPerCall, $rate): array {
            $price = (float) ($site->client?->price_per_camera ?? $organization->price_per_camera ?? 0);
            $calls = DetectionEvent::whereHas('camera', fn ($q) => $q->withTrashed()->where('site_id', $site->id))
                ->whereBetween('started_at', [$from, $to])->whereNotNull('ai_verdict->model')->count()
                + (int) Patrol::where('site_id', $site->id)->whereBetween('started_at', [$from, $to])->sum('cameras_checked');
            $m = $this->metrics->forSites([$site->id], $from, $to);
            $savedHours = round(($m['filtered_by_ai'] + $m['outside_schedule']) * (int) config('vigilia.ai.manual_review_seconds') / 3600, 1);
            $fee = round($site->cameras_count * $price, 2);
            $aiCost = round($calls * $costPerCall * $rate, 2);

            return [
                'id' => $site->id,
                'name' => $site->name,
                'client_id' => $site->client_id,
                'client' => $site->client?->name,
                'cameras' => $site->cameras_count,
                'fee' => $fee,
                'ai_calls' => $calls,
                'ai_cost_usd' => round($calls * $costPerCall, 2),
                'ai_cost' => $aiCost,
                'alerts' => $m['incidents'],
                'escalated' => $m['escalated'],
                'saved_hours' => $savedHours,
                'saved_value' => round($savedHours * (float) ($organization->operator_hour_cost ?? 0), 2),
                'margin' => round($fee - $aiCost, 2),
            ];
        });

        $clients = Client::where('organization_id', $organization->id)->orderBy('name')->get()->map(function (Client $client) use ($rows, $from, $to): array {
            $mine = $rows->where('client_id', $client->id);

            return [
                'id' => $client->id,
                'name' => $client->name,
                'sites' => $mine->count(),
                'cameras' => $mine->sum('cameras'),
                'fee' => round($mine->sum('fee'), 2),
                'ai_cost' => round($mine->sum('ai_cost'), 2),
                'price_per_camera' => $client->price_per_camera !== null ? (float) $client->price_per_camera : null,
                'sla_ack_seconds' => $client->sla_ack_seconds,
                'sla' => $this->sla($client, $mine->pluck('id')->all(), $from, $to),
            ];
        });

        return [
            'sites' => $rows->values()->all(),
            'clients' => $clients->values()->all(),
            'totals' => [
                'fee' => round($rows->sum('fee'), 2),
                'ai_cost' => round($rows->sum('ai_cost'), 2),
                'ai_cost_usd' => round($rows->sum('ai_cost_usd'), 2),
                'saved_hours' => round($rows->sum('saved_hours'), 1),
                'margin' => round($rows->sum('margin'), 2),
            ],
        ];
    }

    /**
     * H4 — share of the client's alerts answered within the promised time.
     *
     * @param  array<int, int>  $siteIds
     * @return array{answered_in_time: int, alerts: int, percent: float|null}|null
     */
    private function sla(Client $client, array $siteIds, CarbonInterface $from, CarbonInterface $to): ?array
    {
        if (! $client->sla_ack_seconds) {
            return null;
        }

        /** @var Collection<int, Incident> $incidents */
        $incidents = Incident::whereIn('site_id', $siteIds)->whereBetween('detected_at', [$from, $to])
            ->whereNotNull('notified_at')->where('source', '!=', IncidentSource::Equipment)->get(['detected_at', 'acknowledged_at']);
        $inTime = $incidents->filter(fn (Incident $incident) => $incident->acknowledged_at
            && $incident->detected_at->diffInSeconds($incident->acknowledged_at, absolute: true) <= $client->sla_ack_seconds)->count();

        return ['answered_in_time' => $inTime, 'alerts' => $incidents->count(), 'percent' => $incidents->isEmpty() ? null : round(100 * $inTime / $incidents->count(), 1)];
    }
}
