<?php

namespace App\Http\Controllers;

use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\IncidentChanged;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Patrol;
use App\Services\Activity\ActivityFeed;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Incidents\IncidentWorkflow;
use App\Services\Sites\SiteScope;
use App\Services\Tracking\IntruderFollow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(Request $request, SiteScope $scope): Response
    {
        $filter = $request->query('filter', 'open');

        $incidents = Incident::query()
            ->with(['site:id,name,address', 'camera:id,name'])
            ->whereIn('site_id', $scope->siteIds($request))
            ->when($filter === 'open', fn ($query) => $query->where('status', '!=', IncidentStatus::Resolved))
            ->orderByRaw("case when status = 'escalated' then 0 when status in ('new', 'notified') then 1 else 2 end")
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest('detected_at')
            ->limit(100)
            ->get()
            ->map(fn (Incident $incident): array => $this->summary($incident));

        return Inertia::render('incidents/index', [
            'incidents' => $incidents,
            'filter' => $filter,
            'channel' => $request->user()->liveChannel(),
        ]);
    }

    public function show(Request $request, Incident $incident): Response
    {
        Gate::authorize('view', $incident);

        return Inertia::render('incidents/show', [
            'incident' => $this->detail($incident),
            'channel' => $request->user()->liveChannel(),
        ]);
    }

    /**
     * Everything the incident page shows; also used by the incident report (B9).
     *
     * @return array<string, mixed>
     */
    public function detail(Incident $incident): array
    {
        $incident->load(['site:id,name,address,organization_id', 'camera:id,name,sector,view_status', 'zone:id,name', 'reporter:id,name,lot,phone', 'acknowledgedBy:id,name', 'actions.user:id,name', 'snapshotRequests.camera:id,name']);

        return $this->summary($incident) + [
            'description' => $incident->description,
            'ai_confidence' => $incident->ai_confidence,
            'zone' => $incident->zone?->name,
            'sector' => $incident->sector ?? $incident->camera?->sector,
            'tamper' => $incident->source === IncidentSource::Tamper && $incident->camera ? [
                'camera_id' => $incident->camera->id,
                'before_url' => $incident->mediaUrl('before'),
                'shift_deg' => $incident->actions->firstWhere('type', 'tamper')?->meta['shift_deg'] ?? null,
                'view_status' => $incident->camera->view_status->value,
                'can_fix' => Gate::allows('manage', $incident->site),
            ] : null,
            'route' => $this->route($incident),
            'follow' => app(IntruderFollow::class)->for($incident),
            'decisions' => $incident->detectionEvents()->with('camera.site:id,name')->orderBy('started_at')->get()->map(fn ($event) => app(ActivityFeed::class)->item($event)),
            'reporter' => $incident->reporter ? ['name' => $incident->reporter->name, 'lot' => $incident->reporter->lot, 'phone' => $incident->reporter->phone] : null,
            'live_frames' => $incident->source === IncidentSource::Patrol
                ? collect(Patrol::where('incident_id', $incident->id)->latest('id')->first()?->toPayload()['findings'] ?? [])
                    ->map(fn (array $finding): array => ['camera' => "{$finding['camera']}: {$finding['kind']}", 'url' => $finding['frame_url']])
                : $incident->snapshotRequests->whereNotNull('snapshot_path')->values()->map(fn ($snapshot): array => [
                    'camera' => $snapshot->camera->name,
                    'url' => URL::temporarySignedRoute('snapshots.media', now()->addHours(12), ['snapshotRequest' => $snapshot->id]),
                ]),
            'snapshot_url' => $incident->mediaUrl('snapshot'),
            'clip_url' => $incident->mediaUrl('clip'),
            'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $incident->acknowledgedBy?->name,
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'resolution' => $incident->resolution?->value,
            'actions' => $incident->actions->sortByDesc('id')->values()->map(fn (IncidentAction $action): array => [
                'id' => $action->id,
                'type' => $action->type,
                'channel' => $action->channel,
                'notes' => $action->notes,
                'user' => $action->user?->name,
                'created_at' => $action->created_at?->toIso8601String(),
            ]),
        ];
    }

    public function acknowledge(Request $request, Incident $incident, IncidentWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('act', $incident);
        $workflow->acknowledge($incident, $request->user(), 'web');

        return back();
    }

    public function resolve(Request $request, Incident $incident, IncidentWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('act', $incident);

        $data = $request->validate([
            // Equipment faults close as "recovered"; security incidents as confirmed or false alarm.
            'resolution' => ['required', Rule::enum(IncidentResolution::class)->only($incident->source === IncidentSource::Equipment
                ? [IncidentResolution::Recovered]
                : [IncidentResolution::Confirmed, IncidentResolution::FalseAlarm])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lesson' => ['nullable', 'string', 'max:200'],
        ]);

        $resolved = $workflow->resolve($incident, IncidentResolution::from($data['resolution']), $request->user(), 'web', $data['notes'] ?? $data['lesson'] ?? null);

        // I6 — what the guard says it was becomes context for the AI on that camera.
        if ($resolved && ! empty($data['lesson']) && $data['resolution'] === IncidentResolution::FalseAlarm->value && $incident->camera) {
            $incident->camera->learn($data['lesson']);
        }

        return back();
    }

    /**
     * After a "camera moved/covered" alert: accept the current view as reference, optionally rotating the
     * camera on the map by the estimated angle (when it was turned on purpose).
     */
    public function fixCameraView(Request $request, Incident $incident, CameraViewMonitor $monitor): RedirectResponse
    {
        Gate::authorize('manage', $incident->site);
        abort_unless($incident->source === IncidentSource::Tamper && $incident->camera, 404);

        $data = $request->validate(['apply_shift' => ['boolean']]);
        $camera = $incident->camera;
        $shift = $incident->actions()->where('type', 'tamper')->latest('id')->value('meta')['shift_deg'] ?? null;

        if (($data['apply_shift'] ?? false) && $shift !== null) {
            $camera->update(['heading' => (($camera->heading + $shift) % 360 + 360) % 360]);
        }

        $monitor->rebaseline($camera);

        $incident->actions()->create([
            'type' => ($data['apply_shift'] ?? false) ? 'view_rotated' : 'view_rebaselined',
            'user_id' => $request->user()->id,
            'channel' => 'web',
            'notes' => ($data['apply_shift'] ?? false) && $shift !== null ? "Se giró la cámara {$shift}° en el mapa y se tomó la vista actual como referencia." : 'Se tomó la vista actual como nueva referencia.',
        ]);
        IncidentChanged::dispatch($incident, 'view_fixed');

        return back();
    }

    public function note(Request $request, Incident $incident, IncidentWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('act', $incident);

        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        $workflow->addNote($incident, $request->user(), $data['notes']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'status' => $incident->status->value,
            'severity' => $incident->severity->value,
            'site' => ['id' => $incident->site->id, 'name' => $incident->site->name, 'address' => $incident->site->address],
            'camera' => $incident->camera?->name ?? ($incident->sector ? "Sector {$incident->sector}" : 'Sin cámara'),
            'source' => $incident->source->value,
            'thumbnail_url' => $incident->mediaUrl('snapshot'),
            'detected_at' => $incident->detected_at->toIso8601String(),
            'escalated_at' => $incident->escalated_at?->toIso8601String(),
        ];
    }

    /**
     * Where the person was seen, camera by camera, when the incident follows someone across cameras.
     *
     * @return array<int, array{camera: string, position: ?array{0: float, 1: float}, at: string}>
     */
    private function route(Incident $incident): array
    {
        $steps = [];
        foreach ($incident->detectionEvents()->with('camera:id,name,lat,lng')->orderBy('started_at')->get() as $event) {
            $last = end($steps);
            if ($last && $last['camera_id'] === $event->camera_id) {
                continue;
            }
            $steps[] = [
                'camera_id' => $event->camera_id,
                'camera' => $event->camera->name,
                'position' => $event->camera->lat !== null ? [$event->camera->lat, $event->camera->lng] : null,
                'at' => $event->started_at->toIso8601String(),
            ];
        }

        return count($steps) >= 2 ? $steps : [];
    }
}
