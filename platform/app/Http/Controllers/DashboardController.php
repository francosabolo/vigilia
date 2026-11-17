<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Services\Activity\ActivityFeed;
use App\Services\Reports\SiteMetrics;
use App\Services\Sites\SiteScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SiteMetrics $metrics, SiteScope $scope, ActivityFeed $activity): Response
    {
        $siteIds = $scope->siteIds($request);
        $offlineAfter = now()->subSeconds(config('seguria.edge_offline_after'));

        $open = Incident::query()
            ->with(['site:id,name', 'camera:id,name'])
            ->whereIn('site_id', $siteIds)
            ->where('status', '!=', IncidentStatus::Resolved)
            ->orderByRaw("case when status = 'escalated' then 0 when status in ('new', 'notified') then 1 else 2 end")
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest('detected_at')
            ->get();

        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->withCount([
                'cameras',
                'cameras as cameras_online_count' => fn ($query) => $query->where('is_online', true),
                'incidents as open_incidents_count' => fn ($query) => $query->where('status', '!=', IncidentStatus::Resolved),
                'edgeDevices as edges_offline_count' => fn ($query) => $query->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $offlineAfter)),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'kind' => $site->kind->value,
                'cameras' => $site->cameras_count,
                'cameras_online' => $site->cameras_online_count,
                'open_incidents' => $site->open_incidents_count,
                'edges_offline' => $site->edges_offline_count,
            ]);

        return Inertia::render('dashboard', [
            'metrics' => $metrics->forSites($siteIds, now()->subDays(7), now()),
            'openIncidents' => $open->take(5)->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'status' => $incident->status->value,
                'severity' => $incident->severity->value,
                'site' => $incident->site->name,
                'detected_at' => $incident->detected_at->toIso8601String(),
            ])->values(),
            'openCount' => $open->count(),
            'escalatedCount' => $open->where('status', IncidentStatus::Escalated)->count(),
            'edgesOffline' => EdgeDevice::whereIn('site_id', $siteIds)
                ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $offlineAfter))
                ->count(),
            'sites' => $sites,
            'activity' => $activity->latest($siteIds, 30),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'channel' => $request->user()->liveChannel(),
            // C5 — the guard who comes in reads how the previous shift ended; one site in view = it can hand over.
            'lastHandover' => ShiftHandover::whereIn('site_id', $siteIds)->with(['site:id,name', 'user:id,name'])->latest()->first()?->toPayload(),
            'handoverSiteId' => count($siteIds) === 1 ? $siteIds[0] : null,
        ]);
    }
}
