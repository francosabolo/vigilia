<?php

namespace App\Http\Controllers;

use App\Models\DetectionEvent;
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
        ]);
    }

    public function label(Request $request, DetectionEvent $detectionEvent, SiteScope $scope): RedirectResponse
    {
        abort_unless($request->user()->isCentral() && in_array($detectionEvent->camera?->site_id, $scope->siteIds($request), true), 403);
        $data = $request->validate(['missed' => ['required', 'boolean']]);

        $detectionEvent->forceFill(['ground_truth' => $data['missed'], 'ground_truth_source' => 'qa', 'reviewed_by' => $request->user()->id])->save();

        return back();
    }
}
