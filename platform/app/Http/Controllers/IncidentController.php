<?php

namespace App\Http\Controllers;

use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Services\Incidents\IncidentWorkflow;
use App\Services\Sites\SiteScope;
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
            ->with(['site:id,name', 'camera:id,name'])
            ->whereIn('site_id', $scope->siteIds($request))
            ->when($filter === 'open', fn ($query) => $query->where('status', '!=', IncidentStatus::Resolved))
            ->orderByRaw("case when status = 'escalated' then 0 when status in ('new', 'notified') then 1 else 2 end")
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

        $incident->load(['site:id,name,organization_id', 'camera:id,name,sector', 'zone:id,name', 'reporter:id,name,lot,phone', 'acknowledgedBy:id,name', 'actions.user:id,name', 'snapshotRequests.camera:id,name']);

        return Inertia::render('incidents/show', [
            'incident' => $this->summary($incident) + [
                'description' => $incident->description,
                'ai_confidence' => $incident->ai_confidence,
                'zone' => $incident->zone?->name,
                'sector' => $incident->sector ?? $incident->camera?->sector,
                'reporter' => $incident->reporter ? ['name' => $incident->reporter->name, 'lot' => $incident->reporter->lot, 'phone' => $incident->reporter->phone] : null,
                'live_frames' => $incident->snapshotRequests->whereNotNull('snapshot_path')->values()->map(fn ($snapshot): array => [
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
            ],
            'channel' => $request->user()->liveChannel(),
        ]);
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
            'resolution' => ['required', Rule::enum(IncidentResolution::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $workflow->resolve($incident, IncidentResolution::from($data['resolution']), $request->user(), 'web', $data['notes'] ?? null);

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
            'site' => ['id' => $incident->site->id, 'name' => $incident->site->name],
            'camera' => $incident->camera?->name ?? ($incident->sector ? "Sector {$incident->sector}" : 'Sin cámara'),
            'source' => $incident->source->value,
            'thumbnail_url' => $incident->mediaUrl('snapshot'),
            'detected_at' => $incident->detected_at->toIso8601String(),
            'escalated_at' => $incident->escalated_at?->toIso8601String(),
        ];
    }
}
