<?php

namespace App\Http\Controllers;

use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\SafeArrival;
use App\Services\Reports\SiteMetrics;
use App\Services\Residents\ResidentAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The residents' app: panic button, "I saw something", vacation mode and sector notices.
 * Deliberately shows no images, no camera feeds and no data about other people.
 */
class ResidentController extends Controller
{
    public function home(Request $request, SiteMetrics $metrics): Response
    {
        $resident = $request->user()->load('site');

        $notices = Incident::query()
            ->where('site_id', $resident->site_id)
            ->where('detected_at', '>', now()->subDays(7))
            ->where(fn ($query) => $query
                ->where('reported_by', $resident->id)
                ->when($resident->sector, fn ($query, string $sector) => $query->orWhere('sector', $sector)))
            ->latest('detected_at')
            ->limit(20)
            ->get()
            ->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'kind' => $incident->source->value,
                'mine' => $incident->reported_by === $resident->id,
                'sector' => $incident->sector,
                'status' => $incident->status->value,
                'resolution' => $incident->resolution?->value,
                'detected_at' => $incident->detected_at->toIso8601String(),
            ]);

        return Inertia::render('residents/home', [
            'resident' => [
                'name' => $resident->name,
                'lot' => $resident->lot,
                'sector' => $resident->sector,
                'away_until' => $resident->isAway() ? $resident->away_until->toDateString() : null,
                'site' => $resident->site?->name,
                'site_kind' => $resident->site?->kind->value ?? 'building',
            ],
            'sectors' => $this->sectors($resident->site_id),
            'notices' => $notices,
            'metrics' => $metrics->forSites([$resident->site_id], now()->subDays(30), now()),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'arrival' => SafeArrival::where('user_id', $resident->id)->where('status', 'arriving')->latest('id')->first()?->toPayload(),
            'contacts' => $resident->contacts()->get()->map->toPayload(),
            'visits' => AccessAuthorization::where('resident_id', $resident->id)->whereNull('revoked_at')->where('valid_until', '>=', now()->toDateString())
                ->with('site:id,timezone')->orderBy('valid_from')->get()->map->toPayload(),
            'atGate' => AccessEntry::where('resident_id', $resident->id)->where('created_at', '>', now()->subDay())->latest()->limit(10)->get()->map->toPayload(),
        ]);
    }

    public function panic(Request $request, ResidentAlerts $alerts): RedirectResponse
    {
        $alerts->panic($request->user(), $request->validate(['notes' => ['nullable', 'string', 'max:500']])['notes'] ?? null);

        return back()->with('status', 'panic_sent');
    }

    public function report(Request $request, ResidentAlerts $alerts): RedirectResponse
    {
        $data = $request->validate([
            'sector' => ['required', 'string', Rule::in($this->sectors($request->user()->site_id))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $alerts->report($request->user(), $data['sector'], $data['notes'] ?? null);

        return back()->with('status', 'report_sent');
    }

    public function updateAway(Request $request): RedirectResponse
    {
        $data = $request->validate(['away_until' => ['nullable', 'date', 'after:today', 'before:+3 months']]);

        abort_if(($data['away_until'] ?? null) && ! $request->user()->lot, 422, 'Tu lote no está cargado: pedile a la administración que lo complete.');

        $request->user()->update(['away_until' => ($data['away_until'] ?? null) ? now()->parse($data['away_until'])->endOfDay() : null]);

        return back();
    }

    /**
     * @return array<int, string>
     */
    private function sectors(?int $siteId): array
    {
        return Camera::where('site_id', $siteId)->whereNotNull('sector')->distinct()->orderBy('sector')->pluck('sector')->all();
    }
}
