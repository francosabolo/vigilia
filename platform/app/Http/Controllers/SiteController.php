<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Enums\SiteKind;
use App\Enums\UserRole;
use App\Models\ArmingSchedule;
use App\Models\Camera;
use App\Models\Client;
use App\Models\DeterrenceCommand;
use App\Models\EdgeDevice;
use App\Models\MediaView;
use App\Models\Patrol;
use App\Models\ResidentMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\Autonomy\AutonomyPolicy;
use App\Services\Cameras\CameraWall;
use App\Services\Patrol\VirtualPatrol;
use App\Services\Privacy\MediaAccessLog;
use App\Services\Reports\SiteMetrics;
use App\Services\Residents\ResidentDevices;
use App\Services\Sites\SiteArchive;
use App\Services\Sites\SiteScope;
use App\Services\Verification\AiBudget;
use App\Support\Provinces;
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
            ->with(['client:id,name', 'edgeDevices:id,site_id,name,last_seen_at,version', 'cameras:id,site_id,name,is_online,last_seen_at'])
            ->withCount(['incidents as open_incidents_count' => fn ($query) => $query->where('status', '!=', IncidentStatus::Resolved)])
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'kind' => $site->kind->value,
                'client' => $site->client?->name,
                'open_incidents' => $site->open_incidents_count,
                'cameras_total' => $site->cameras->count(),
                'cameras_online' => $site->cameras->where('is_online', true)->count(),
                'edges' => $site->edgeDevices->map(fn (EdgeDevice $edge): array => $this->edge($edge)),
            ]);

        $canCreate = $request->user()->role === UserRole::Admin;

        return Inertia::render('sites/index', [
            'sites' => $sites,
            'canCreate' => $canCreate,
            'clients' => $canCreate ? Client::where('organization_id', $request->user()->organization_id)->orderBy('name')->get(['id', 'name']) : [],
        ]);
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
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', Rule::in(Provinces::ALL)],
            'client_id' => ['nullable', Rule::exists('clients', 'id')->where('organization_id', $request->user()->organization_id)],
        ]);

        $site = Site::create($data + ['organization_id' => $request->user()->organization_id]);

        // K15 — a new property continues in the guided setup.
        return to_route('onboarding.show', ['site' => $site, 'step' => 'ubicacion']);
    }

    /**
     * Name, address and kind: the site's managers. Which client it belongs to: the central admin only.
     */
    public function updateDetails(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manage', $site);
        $isAdmin = $request->user()->role === UserRole::Admin;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', Rule::in(Provinces::ALL)],
            'kind' => ['required', Rule::enum(SiteKind::class)],
            'client_id' => ['nullable', Rule::exists('clients', 'id')->where('organization_id', $site->organization_id)],
        ]);
        if (! $isAdmin) {
            unset($data['client_id']);
        }

        $site->update($data);

        return back();
    }

    /**
     * K14 — the site leaves the service. The admin confirms by typing its name.
     */
    public function destroy(Request $request, Site $site, SiteArchive $archive): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin && $site->organization_id === $request->user()->organization_id, 403);

        $request->validate(['confirm' => ['required', 'string', Rule::in([$site->name])]], [
            'confirm.in' => 'Escribí el nombre exacto del lugar para confirmar.',
        ]);

        $archive->archive($site);

        return to_route('sites.index');
    }

    public function show(Request $request, Site $site, SiteMetrics $metrics, CameraWall $wall, ResidentDevices $devices, AiBudget $budget, AutonomyPolicy $autonomy): Response
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
            'isAdmin' => $request->user()->role === UserRole::Admin,
            'clients' => $canManage && $request->user()->role === UserRole::Admin ? Client::where('organization_id', $site->organization_id)->orderBy('name')->get(['id', 'name']) : [],
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
                'city' => $site->city,
                'province' => $site->province,
                'full_address' => $site->fullAddress(),
                'client_id' => $site->client_id,
                'perimeter' => $site->perimeter ?? [],
                'center' => $site->lat !== null ? [$site->lat, $site->lng] : null,
                'guard_whatsapp' => $site->guard_whatsapp,
                'ack_timeout_seconds' => $site->ack_timeout_seconds,
                'patrol_interval_minutes' => $site->patrol_interval_minutes,
                'guard_post_max_absence_minutes' => $site->guard_post_max_absence_minutes,
                'round_interval_minutes' => $site->round_interval_minutes,
                'guard_monitoring_consent_at' => $site->guard_monitoring_consent_at?->toIso8601String(),
                'open_incidents' => $site->incidents()->where('status', '!=', IncidentStatus::Resolved)->count(),
                'escalated_incidents' => $site->incidents()->where('status', IncidentStatus::Escalated)->count(),
                'escalated_incident_id' => $site->incidents()->where('status', IncidentStatus::Escalated)->latest('detected_at')->value('id'),
                'edges' => $site->edgeDevices->map(fn (EdgeDevice $edge): array => $this->edge($edge)),
                'cameras' => $site->cameras->sortBy([['sector', 'asc'], ['name', 'asc']])->values()->map(function (Camera $camera) use ($wall, $autonomy, $canManage): array {
                    $previewAt = $wall->previewAt($camera);

                    return [
                        'id' => $camera->id,
                        'name' => $camera->name,
                        'sector' => $camera->sector,
                        'is_online' => $camera->is_online,
                        'view_status' => $camera->view_status->value,
                        'is_guard_post' => $camera->is_guard_post,
                        'is_checkpoint' => $camera->is_checkpoint,
                        'is_access' => $camera->is_access,
                        'is_ptz' => $camera->is_ptz,
                        'watch_motion' => $camera->watch_motion,
                        'fire_watch' => $camera->fire_watch,
                        'siren' => $camera->siren ? ['brand' => $camera->siren['brand'], 'host' => $camera->siren['host'], 'user' => $camera->siren['user']] : null,
                        'ai_notes' => $canManage ? $camera->ai_notes : null,
                        'speaker_test' => ($test = DeterrenceCommand::where('camera_id', $camera->id)->whereNull('incident_id')->latest('id')->first())
                            ? ['status' => $test->status, 'result' => $test->result, 'at' => $test->created_at->toIso8601String()]
                            : null,
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
                            'autonomy' => $zone->autonomy->value,
                            'autonomy_eligibility' => $canManage ? $autonomy->eligibility($zone) : null,
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
            'residentMessages' => $tab === 'vecinos'
                ? ResidentMessage::where('site_id', $site->id)->with('sender:id,name')->latest()->limit(5)->get()->map(fn (ResidentMessage $message): array => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'recipients' => $message->recipients,
                    'urgent' => $message->urgent,
                    'sender' => $message->sender?->name,
                    'at' => $message->created_at->toIso8601String(),
                ])
                : [],
            'sectors' => $site->cameras->pluck('sector')->filter()->unique()->sort()->values(),
            'metrics' => $metrics->forSites([$site->id], now()->subDays(7), now()),
            'mediaViews' => $tab === 'ajustes' && $canManage
                ? MediaView::where('site_id', $site->id)->with('user:id,name,role')->latest('viewed_at')->limit(50)->get()->map(fn (MediaView $view): array => [
                    'id' => $view->id,
                    'who' => $view->user?->name ?? "Enlace sin sesión ({$view->ip})",
                    'kind' => MediaAccessLog::KINDS[$view->kind] ?? $view->kind,
                    'subject' => $view->subject,
                    'at' => $view->viewed_at->toIso8601String(),
                ])
                : null,
            'setup' => $canManage ? collect(SiteSetupController::steps($site))->where('optional', false)->pipe(fn ($steps) => ['done' => $steps->where('done', true)->count(), 'total' => $steps->count()]) : null,
            'testModeUntil' => $site->inTestMode() ? $site->test_mode_until->toIso8601String() : null,
            'patrols' => $site->patrols()->with('requester:id,name')->latest('started_at')->limit(6)->get()->map(fn (Patrol $patrol): array => $patrol->toPayload()),
            'patrolIntervals' => VirtualPatrol::INTERVALS,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function residents(Site $site, ResidentDevices $devices): array
    {
        $residents = $site->residents()->with('contacts', 'vehicles')->get();
        $usage = $devices->forUsers($residents->pluck('id')->all());

        return $residents->map(fn (User $resident): array => [
            'id' => $resident->id,
            'name' => $resident->name,
            'email' => $resident->email,
            'phone' => $resident->phone,
            'lot' => $resident->lot,
            'sector' => $resident->sector,
            'contacts' => $resident->contacts->map->toPayload(),
            'vehicles' => $resident->vehicles->map->toPayload(),
            'home' => $resident->home_lat !== null ? [(float) $resident->home_lat, (float) $resident->home_lng] : null,
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
            'online' => $edge->last_seen_at !== null && $edge->last_seen_at->gt(now()->subSeconds(config('vigilia.edge_offline_after'))),
        ];
    }
}
