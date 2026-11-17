<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Site;
use App\Services\Evidence\EvidenceExport;
use App\Services\Privacy\MediaAccessLog;
use App\Services\Reports\IncidentReport;
use App\Services\Reports\MonthlyReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Printable reports: the browser saves them as PDF.
 */
class ReportController extends Controller
{
    /**
     * G1 — monthly service report of a site. ?month=YYYY-MM, the current month by default.
     */
    public function site(Request $request, Site $site, MonthlyReport $reports): Response
    {
        Gate::authorize('view', $site);

        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        abort_if(MonthlyReport::monthStart($month)->isAfter(now()), 404);

        $first = $site->created_at?->startOfMonth() ?? now()->startOfMonth();
        $months = collect();
        for ($cursor = now()->startOfMonth(); $cursor->gte($first) && $months->count() < 24; $cursor = $cursor->subMonth()) {
            $months->push($cursor->format('Y-m'));
        }

        return Inertia::render('reports/site', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'kind' => $site->kind->value, 'address' => $site->address, 'client' => $site->client?->name],
            'report' => $reports->build($site, $month),
            'months' => $months,
            'organization' => $site->organization->name,
        ]);
    }

    /**
     * M19 — original images, clip and record with their SHA-256, for the chain of custody. Logged as an image view.
     */
    public function evidence(Request $request, Incident $incident, EvidenceExport $export, MediaAccessLog $log): BinaryFileResponse
    {
        Gate::authorize('view', $incident);
        $log->record($request, $incident->site_id, 'evidence', "Evidencia del incidente {$incident->id}", "evidence:{$incident->id}:".now()->timestamp);

        return response()->download($export->build($incident, $request->user()), "evidencia-incidente-{$incident->id}.zip")->deleteFileAfterSend();
    }

    /**
     * B9 — incident report for a police complaint or an insurance claim.
     */
    public function incident(Incident $incident, IncidentReport $reports, IncidentController $incidents): Response
    {
        Gate::authorize('view', $incident);

        return Inertia::render('reports/incident', [
            'incident' => $incidents->detail($incident),
            'narrative' => $reports->narrative($incident),
            'site' => ['name' => $incident->site->name, 'address' => $incident->site->address, 'client' => $incident->site->client?->name],
            'organization' => $incident->site->organization->name,
            'generatedAt' => now()->toIso8601String(),
        ]);
    }
}
