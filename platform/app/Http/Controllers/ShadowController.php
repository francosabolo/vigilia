<?php

namespace App\Http\Controllers;

use App\Models\DetectionEvent;
use App\Models\ShadowVerdict;
use App\Services\Shadow\ShadowMode;
use App\Services\Shadow\ShadowReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Pruebas de IA": production vs a challenger in shadow mode. Central staff only.
 */
class ShadowController extends Controller
{
    public function index(Request $request, ShadowReport $report, ShadowMode $shadow): Response
    {
        abort_unless($request->user()->isCentral(), 403);

        $siteIds = $request->user()->accessibleSiteIds();
        $challengers = $report->challengers($siteIds);
        $current = $shadow->challenger()['label'];
        $selected = $request->query('challenger') ?: ($challengers[0]['label'] ?? $current);

        return Inertia::render('ai/shadow', [
            'enabled' => $shadow->enabled(),
            'current' => $current,
            'production' => config('vigilia.ai.model').' con revisión de '.config('vigilia.ai.review_model'),
            'challengers' => $challengers,
            'report' => $report->for($selected, $siteIds),
            // In the demo, "production" on simulated sites is the simulator, which errs on purpose.
            'fromSimulation' => ShadowVerdict::query()
                ->whereHas('detectionEvent.camera.site', fn ($query) => $query->whereIn('id', $siteIds)->where('is_simulated', true))
                ->exists(),
        ]);
    }

    /**
     * A person says what the detection really was: it becomes ground truth for shadow mode and evaluations.
     */
    public function label(Request $request, DetectionEvent $detectionEvent): RedirectResponse
    {
        abort_unless($request->user()->isCentral(), 403);
        Gate::authorize('view', $detectionEvent->camera->site);
        abort_if($detectionEvent->ground_truth_source === 'simulation', 422, 'The simulator already knows what this was.');

        $data = $request->validate(['threat' => ['required', 'boolean']]);
        $detectionEvent->forceFill([
            'ground_truth' => $data['threat'],
            'ground_truth_source' => 'review',
            'reviewed_by' => $request->user()->id,
        ])->save();

        return back();
    }
}
