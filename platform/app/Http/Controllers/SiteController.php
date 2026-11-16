<?php

namespace App\Http\Controllers;

use App\Models\ArmingSchedule;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\Reports\SiteMetrics;
use App\Services\Sites\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(Request $request, SiteScope $scope): Response
    {
        $sites = Site::query()
            ->whereIn('id', $scope->siteIds($request))
            ->with(['edgeDevices:id,site_id,name,last_seen_at,version', 'cameras:id,site_id,name,is_online,last_seen_at'])
            ->withCount(['incidents as open_incidents_count' => fn ($query) => $query->where('status', '!=', 'resolved')])
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'open_incidents' => $site->open_incidents_count,
                'cameras_total' => $site->cameras->count(),
                'cameras_online' => $site->cameras->where('is_online', true)->count(),
                'edges' => $site->edgeDevices->map(fn (EdgeDevice $edge): array => $this->edge($edge)),
            ]);

        return Inertia::render('sites/index', ['sites' => $sites]);
    }

    public function show(Request $request, Site $site, SiteMetrics $metrics): Response
    {
        Gate::authorize('view', $site);

        $site->load(['edgeDevices', 'cameras.zones.armingSchedules', 'residents']);

        return Inertia::render('sites/show', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'address' => $site->address,
                'timezone' => $site->timezone,
                'guard_whatsapp' => $site->guard_whatsapp,
                'ack_timeout_seconds' => $site->ack_timeout_seconds,
                'edges' => $site->edgeDevices->map(fn (EdgeDevice $edge): array => $this->edge($edge)),
                'cameras' => $site->cameras->map(fn (Camera $camera): array => [
                    'id' => $camera->id,
                    'name' => $camera->name,
                    'frigate_name' => $camera->frigate_name,
                    'sector' => $camera->sector,
                    'is_online' => $camera->is_online,
                    'last_seen_at' => $camera->last_seen_at?->toIso8601String(),
                    'zones' => $camera->zones->map(fn (Zone $zone): array => [
                        'id' => $zone->id,
                        'name' => $zone->name,
                        'lot' => $zone->lot,
                        'has_polygon' => ! empty($zone->coordinates),
                        'frigate_zone' => $zone->frigate_zone,
                        'watched_labels' => $zone->watched_labels ?? [],
                        'schedules' => $zone->armingSchedules->map(fn (ArmingSchedule $schedule): array => [
                            'id' => $schedule->id,
                            'days' => $schedule->days,
                            'starts_at' => substr($schedule->starts_at, 0, 5),
                            'ends_at' => substr($schedule->ends_at, 0, 5),
                        ]),
                    ]),
                ]),
            ],
            'residents' => $site->residents->map(fn (User $resident): array => [
                'id' => $resident->id,
                'name' => $resident->name,
                'email' => $resident->email,
                'lot' => $resident->lot,
                'sector' => $resident->sector,
                'away' => $resident->isAway(),
            ]),
            'sectors' => $site->cameras->pluck('sector')->filter()->unique()->values(),
            'metrics' => $metrics->forSites([$site->id], now()->subDays(7), now()),
            'canManage' => Gate::allows('manage', $site),
        ]);
    }

    /**
     * @return array{id: int, name: string, version: ?string, last_seen_at: ?string, online: bool}
     */
    private function edge(EdgeDevice $edge): array
    {
        return [
            'id' => $edge->id,
            'name' => $edge->name,
            'version' => $edge->version,
            'last_seen_at' => $edge->last_seen_at?->toIso8601String(),
            'online' => $edge->last_seen_at !== null && $edge->last_seen_at->gt(now()->subSeconds(config('seguria.edge_offline_after'))),
        ];
    }
}
