<?php

namespace App\Http\Controllers;

use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Services\Activity\ActivityFeed;
use App\Services\Quality\DismissedSample;
use App\Services\Sites\SiteScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * I7 — the weekly review of what the AI dismissed, and the resulting miss and false alarm rates.
 */
class QualityController extends Controller
{
    public function index(Request $request, SiteScope $scope, DismissedSample $sample, ActivityFeed $feed): Response
    {
        abort_unless($request->user()->isCentral(), 403);
        $siteIds = $scope->siteIds($request);

        $pending = DetectionEvent::query()
            ->whereHas('camera', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->whereNotNull('qa_requested_at')->whereNull('ground_truth')
            ->oldest('qa_requested_at')->limit(30)->get();

        return Inertia::render('quality/index', [
            'pending' => $pending->map(fn (DetectionEvent $event) => $feed->item($event)),
            'pendingCount' => DetectionEvent::whereHas('camera', fn ($query) => $query->whereIn('site_id', $siteIds))->whereNotNull('qa_requested_at')->whereNull('ground_truth')->count(),
            'stats' => $sample->stats($siteIds),
            'closures' => Incident::whereIn('site_id', $siteIds)->whereNotNull('qa_requested_at')->whereNull('qa_result')
                ->with(['site:id,name', 'actions' => fn ($query) => $query->where('type', 'false_alarm')->with('user:id,name')])->latest('resolved_at')->limit(20)->get()
                ->map(fn (Incident $incident): array => [
                    'id' => $incident->id, 'title' => $incident->title, 'site' => $incident->site->name,
                    'closed_by' => $incident->actions->last()?->user?->name, 'resolved_at' => $incident->resolved_at?->toIso8601String(),
                    'snapshot_url' => $incident->mediaUrl('snapshot'),
                ]),
        ]);
    }

    public function reviewClosure(Request $request, Incident $incident, SiteScope $scope): RedirectResponse
    {
        abort_unless($request->user()->isCentral() && in_array($incident->site_id, $scope->siteIds($request), true), 403);
        $data = $request->validate(['correct' => ['required', 'boolean']]);

        $incident->forceFill(['qa_result' => $data['correct'] ? 'ok' : 'wrong'])->save();
        $incident->actions()->create([
            'type' => 'note', 'user_id' => $request->user()->id, 'channel' => 'web',
            'notes' => $data['correct'] ? 'Control de calidad: el cierre como falsa alarma fue correcto.' : 'Control de calidad: no era una falsa alarma.',
        ]);

        return back();
    }

    public function label(Request $request, DetectionEvent $detectionEvent, SiteScope $scope): RedirectResponse
    {
        abort_unless($request->user()->isCentral() && in_array($detectionEvent->camera?->site_id, $scope->siteIds($request), true), 403);
        $data = $request->validate(['missed' => ['required', 'boolean']]);

        $detectionEvent->forceFill(['ground_truth' => $data['missed'], 'ground_truth_source' => 'qa', 'reviewed_by' => $request->user()->id])->save();

        return back();
    }
}
