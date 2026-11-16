<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Enums\SiteKind;
use App\Enums\UserRole;
use App\Models\ArmingSchedule;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\Cameras\CameraWall;
use App\Services\Reports\SiteMetrics;
use App\Services\Residents\ResidentDevices;
use App\Services\Sites\SiteScope;
use App\Services\Verification\AiBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    private const TABS = ['resumen', 'cobertura', 'camaras', 'vecinos', 'ajustes'];

    public function index(Request $request, SiteScope $scope): Response
    {
        $sites = Site::query()
            ->whereIn('id', $scope->siteIds($request))
            ->with(['edgeDevices:id,site_id,name,last_seen_at,version', 'cameras:id,site_id,name,is_online,last_seen_at'])
            ->withCount(['incidents as open_incidents_count' => fn ($query) => $query->where('status', '!=', IncidentStatus::Resolved)])
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'kind' => $site->kind->value,
                'open_incidents' => $site->open_incidents_count,
                'cameras_total' => $site->cameras->count(),
                'cameras_online' => $site->cameras->where('is_online', true)->count(),
                'edges' => $site->edgeDevices->map(fn (EdgeDevice $edge): array => $this->edge($edge)),
            ]);

        return Inertia::render('sites/index', ['sites' => $sites, 'canCreate' => $request->user()->role === UserRole::Admin]);
    }

    /**
     * New building or country. The perimeter and the cameras are drawn next, on its map.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(SiteKind::class)],
            'address' => ['required', 'string', 'max:200'],
        ]);

        $site = Site::create($data + ['organization_id' => $request->user()->organization_id]);

        return to_route('sites.show', ['site' => $site, 'tab' => 'cobertura']);
    }

    public function show(Request $request, Site $site, SiteMetrics $metrics, CameraWall $wall, ResidentDevices $devices, AiBudget $budget): Response
    {
        Gate::authorize('view', $site);

        $canManage = Gate::allows('manage', $site);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'resumen';
        if ($tab === 'ajustes' && ! $canManage) {
            $tab = 'resumen';
        }

        $site->load(['edgeDevices', 'cameras.zones.armingSchedules']);
        $wall->markWatched($site->id);

        return Inertia::render('sites/show', [
            'tab' => $tab,
            'canManage' => $canManage,
            'canManageEdges' => $request->user()->isCentral(),
            'newEdge' => $request->session()->get('new_edge'),
            'edgeServerUrl' => url('/'),
            'aiUsage' => $canManage ? ['used' => $budget->usedToday($site->id), 'cap' => $budget->cap()] : null,
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'kind' => $site->kind->value,
                'is_simulated' => $site->is_simulated,
                'address' => $site->address,
                'perimeter' => $site->perimeter ?? [],
                'center' => $site->lat !== null ? [$site->lat, $site->lng] : null,
                'guard_whatsapp' => $site->guard_whatsapp,
                'ack_timeout_seconds' => $site->ack_timeout_seconds,
                'open_incidents' => $site->incidents()->where('status', '!=', IncidentStatus::Resolved)->count(),
                'escalated_incidents' => $site->incidents()->where('status', IncidentStatus::Escalated)->count(),
                'escalated_incident_id' => $site->incidents()->where('status', IncidentStatus::Escalated)->latest('detected_at')->value('id'),
                'edges' => $site->edgeDevices->map(fn (EdgeDevice $edge): array => $this->edge($edge)),
                'cameras' => $site->cameras->sortBy([['sector', 'asc'], ['name', 'asc']])->values()->map(function (Camera $camera) use ($wall): array {
                    $previewAt = $wall->previewAt($camera);

                    return [
                        'id' => $camera->id,
                        'name' => $camera->name,
                        'sector' => $camera->sector,
                        'is_online' => $camera->is_online,
                        'view_status' => $camera->view_status->value,
                        'view_shift_deg' => $camera->view_shift_deg,
                        'position' => $camera->lat !== null ? [$camera->lat, $camera->lng] : null,
                        'heading' => $camera->heading,
                        'fov' => $camera->fov,
                        'range_m' => $camera->range_m,
                        'preview_url' => $previewAt ? route('cameras.preview', ['camera' => $camera, 't' => $previewAt->timestamp]) : null,
                        'zones' => $camera->zones->map(fn (Zone $zone): array => [
                            'id' => $zone->id,
                            'name' => $zone->name,
                            'kind' => $zone->kind->value,
                            'lot' => $zone->lot,
                            'has_polygon' => ! empty($zone->coordinates),
                            'schedules' => $zone->armingSchedules->map(fn (ArmingSchedule $schedule): array => [
                                'days' => $schedule->days,
                                'starts_at' => substr($schedule->starts_at, 0, 5),
                                'ends_at' => substr($schedule->ends_at, 0, 5),
                            ]),
                        ]),
                    ];
                }),
            ],
            'residents' => $tab === 'vecinos'
                ? $this->residents($site, $devices)
                : null,
            'residentsCount' => $site->residents()->count(),
            'sectors' => $site->cameras->pluck('sector')->filter()->unique()->sort()->values(),
            'metrics' => $metrics->forSites([$site->id], now()->subDays(7), now()),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function residents(Site $site, ResidentDevices $devices): array
    {
        $residents = $site->residents()->get();
        $usage = $devices->forUsers($residents->pluck('id')->all());

        return $residents->map(fn (User $resident): array => [
            'id' => $resident->id,
            'name' => $resident->name,
            'email' => $resident->email,
            'phone' => $resident->phone,
            'lot' => $resident->lot,
            'sector' => $resident->sector,
            'away_until' => $resident->isAway() ? $resident->away_until->toDateString() : null,
            'last_login_at' => $resident->last_login_at?->toIso8601String(),
            ...$usage[$resident->id],
        ])->all();
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
