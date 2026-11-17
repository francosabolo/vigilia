<?php

namespace App\Http\Controllers;

use App\Models\AccessEntry;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Services\Activity\ActivityFeed;
use App\Services\Sites\SiteScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A8 — find something in what was recorded with plain words: what the AI described in each detection, incident
 * titles and notes, and the access log (names and plates). Every word has to match.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, SiteScope $scope, ActivityFeed $feed): Response
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'days' => ['nullable', 'integer', 'in:1,7,30,90']]);
        $q = trim($data['q'] ?? '');
        $days = (int) ($data['days'] ?? 7);
        $siteIds = $scope->siteIds($request);
        $words = collect(preg_split('/\s+/', mb_strtolower($q)))->filter(fn ($word) => mb_strlen($word) >= 2)->take(6)->values();

        $results = ['detections' => [], 'incidents' => [], 'entries' => []];

        if ($words->isNotEmpty()) {
            $since = now()->subDays($days);

            $detections = DetectionEvent::query()
                ->whereHas('camera', fn ($query) => $query->whereIn('site_id', $siteIds))
                ->where('started_at', '>=', $since)
                ->where(fn ($query) => $words->each(fn ($word) => $query->whereRaw('LOWER(CAST(ai_verdict AS TEXT)) LIKE ?', ["%{$word}%"])))
                ->latest('started_at')->limit(40)->get();

            $incidents = Incident::query()
                ->whereIn('site_id', $siteIds)
                ->where('detected_at', '>=', $since)
                ->where(fn ($query) => $words->each(fn ($word) => $query->whereRaw("LOWER(title || ' ' || COALESCE(description, '')) LIKE ?", ["%{$word}%"])))
                ->with('site:id,name')->latest('detected_at')->limit(30)->get();

            $entries = AccessEntry::query()
                ->whereIn('site_id', $siteIds)
                ->where('created_at', '>=', $since)
                ->where(fn ($query) => $words->each(fn ($word) => $query->whereRaw("LOWER(name || ' ' || COALESCE(plate, '')) LIKE ?", ["%{$word}%"])))
                ->with(['resident:id,name,lot', 'guardUser:id,name'])->latest()->limit(30)->get();

            $results = [
                'detections' => $detections->map(fn (DetectionEvent $event) => $feed->item($event))->all(),
                'incidents' => $incidents->map(fn (Incident $incident): array => [
                    'id' => $incident->id, 'title' => $incident->title, 'site' => $incident->site->name,
                    'description' => $incident->description, 'at' => $incident->detected_at->toIso8601String(),
                ])->all(),
                'entries' => $entries->map->toPayload()->all(),
            ];
        }

        return Inertia::render('search/index', ['q' => $q, 'days' => $days, 'results' => $results]);
    }
}
