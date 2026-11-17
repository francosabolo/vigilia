<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\GuardSighting;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Services\Guards\GuardPerformance;
use App\Services\Guards\ShiftHandoverWriter;
use App\Services\Sites\SiteScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guard supervision: response times per guard and shift (C4) and shift handovers (C5).
 */
class GuardController extends Controller
{
    public function index(Request $request, SiteScope $scope, GuardPerformance $performance): Response
    {
        abort_if($request->user()->role === UserRole::Guard, 403);

        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;
        $siteIds = $scope->siteIds($request);

        return Inertia::render('guards/index', [
            'days' => $days,
            'report' => $performance->report($siteIds, now()->subDays($days), now()),
            'supervision' => Site::whereIn('id', $siteIds)->whereNotNull('guard_monitoring_consent_at')->with(['cameras' => fn ($query) => $query->where('is_guard_post', true)->orWhere('is_checkpoint', true)])->get()
                ->map(fn (Site $site): array => [
                    'site' => $site->name,
                    'round_interval_minutes' => $site->round_interval_minutes,
                    'guard_post_max_absence_minutes' => $site->guard_post_max_absence_minutes,
                    'points' => $site->cameras->map(function ($camera): array {
                        $last = GuardSighting::where('camera_id', $camera->id)->where('present', true)->latest('seen_at')->first();
                        $check = $camera->is_guard_post ? GuardSighting::where('camera_id', $camera->id)->where('source', 'post_check')->latest('seen_at')->first() : null;

                        return [
                            'camera' => $camera->name,
                            'kind' => $camera->is_guard_post ? 'post' : 'checkpoint',
                            'last_seen_at' => $last?->seen_at->toIso8601String(),
                            'now_present' => $check?->present,
                            'detail' => $check?->description,
                        ];
                    })->values(),
                ]),
            'handovers' => ShiftHandover::whereIn('site_id', $siteIds)->with(['site:id,name', 'user:id,name'])->latest()->limit(10)->get()->map(fn (ShiftHandover $handover) => $handover->toPayload()),
        ]);
    }

    public function handOver(Request $request, Site $site, ShiftHandoverWriter $writer): RedirectResponse
    {
        Gate::authorize('view', $site);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $writer->handOver($site, $request->user(), $data['notes'] ?? null);

        return back()->with('notice', 'Turno entregado. La guardia que entra lo ve en el panel.');
    }
}
