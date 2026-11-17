<?php

namespace App\Services\Shadow;

use App\Models\ShadowVerdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Production vs challenger on the same detections: how often they agree, which one is right when the truth is
 * known, what the challenger costs, and whether it is ready to be promoted.
 */
class ShadowReport
{
    /** Minimum known threats and known non-threats before recommending anything: intrusions are rare. */
    public const MIN_POSITIVES = 10;

    public const MIN_NEGATIVES = 20;

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function challengers(array $siteIds): array
    {
        return $this->scope($siteIds)
            ->selectRaw('challenger, count(*) as total, max(created_at) as last')
            ->groupBy('challenger')
            ->orderByDesc('last')
            ->get()
            ->map(fn ($row): array => ['label' => $row->challenger, 'count' => (int) $row->total])
            ->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return array<string, mixed>
     */
    public function for(string $challenger, array $siteIds): array
    {
        $verdicts = $this->scope($siteIds)->where('challenger', $challenger)->with('detectionEvent.camera.site')->latest()->get();
        $labeled = $verdicts->filter(fn (ShadowVerdict $verdict) => $verdict->detectionEvent->ground_truth !== null);

        $champion = $this->score($labeled, fn (ShadowVerdict $verdict) => $verdict->champion_alerted);
        $candidate = $this->score($labeled, fn (ShadowVerdict $verdict) => $verdict->alerted);
        $latencies = $verdicts->pluck('latency_ms')->filter()->sort()->values();

        return [
            'challenger' => $challenger,
            'compared' => $verdicts->count(),
            'agreement' => $verdicts->isEmpty() ? null : round($verdicts->filter(fn ($verdict) => $verdict->alerted === $verdict->champion_alerted)->count() / $verdicts->count(), 4),
            'labeled' => $labeled->count(),
            'champion' => $champion,
            'candidate' => $candidate,
            'cost_per_1000' => $verdicts->isEmpty() ? null : round($verdicts->avg('cost_usd') * 1000, 2),
            'latency_ms' => $latencies->isEmpty() ? null : (int) $latencies->median(),
            'recommendation' => $this->recommend($champion, $candidate),
            'disagreements' => $verdicts
                ->filter(fn (ShadowVerdict $verdict) => $verdict->alerted !== $verdict->champion_alerted)
                ->take(40)
                ->map(fn (ShadowVerdict $verdict): array => $this->disagreement($verdict))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Builder<ShadowVerdict>
     */
    private function scope(array $siteIds)
    {
        return ShadowVerdict::query()->whereHas('detectionEvent.camera', fn ($query) => $query->whereIn('site_id', $siteIds));
    }

    /**
     * @param  Collection<int, ShadowVerdict>  $labeled
     * @return array{recall: ?float, false_alarm_rate: ?float, positives: int, negatives: int}
     */
    private function score(Collection $labeled, callable $alerted): array
    {
        $positives = $labeled->filter(fn ($verdict) => $verdict->detectionEvent->ground_truth === true);
        $negatives = $labeled->filter(fn ($verdict) => $verdict->detectionEvent->ground_truth === false);

        return [
            'recall' => $positives->isEmpty() ? null : round($positives->filter($alerted)->count() / $positives->count(), 4),
            'false_alarm_rate' => $negatives->isEmpty() ? null : round($negatives->filter($alerted)->count() / $negatives->count(), 4),
            'positives' => $positives->count(),
            'negatives' => $negatives->count(),
        ];
    }

    /**
     * @return array{status: string, text: string}
     */
    private function recommend(array $champion, array $candidate): array
    {
        if ($candidate['positives'] < self::MIN_POSITIVES || $candidate['negatives'] < self::MIN_NEGATIVES) {
            return ['status' => 'waiting', 'text' => sprintf(
                'Todavía no alcanza para decidir: hay %d amenazas reales y %d casos sin amenaza con resultado conocido, y hacen falta al menos %d y %d. Revisá los desacuerdos para sumar.',
                $candidate['positives'], $candidate['negatives'], self::MIN_POSITIVES, self::MIN_NEGATIVES,
            )];
        }

        $recallDelta = ($candidate['recall'] ?? 1) - ($champion['recall'] ?? 1);
        $falseAlarmDelta = ($candidate['false_alarm_rate'] ?? 0) - ($champion['false_alarm_rate'] ?? 0);

        if ($recallDelta < 0) {
            return ['status' => 'reject', 'text' => 'No promover: el retador deja pasar intrusiones que producción detecta.'];
        }
        if ($falseAlarmDelta > 0.02) {
            return ['status' => 'reject', 'text' => 'No promover: detecta lo mismo pero manda más falsas alarmas a la guardia.'];
        }
        if ($recallDelta > 0 || $falseAlarmDelta < -0.02) {
            return ['status' => 'promote', 'text' => 'Candidato a promover: detecta igual o más y molesta menos. Antes, grabalo en el control de calidad (vigilia:eval-gate --record).'];
        }

        return ['status' => 'tie', 'text' => 'Empatan en resultados: decidí por costo y latencia.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function disagreement(ShadowVerdict $verdict): array
    {
        $event = $verdict->detectionEvent;

        return [
            'id' => $verdict->id,
            'detection_id' => $event->id,
            'site' => $event->camera->site->name,
            'camera' => $event->camera->name,
            'at' => $event->started_at->toIso8601String(),
            'snapshot_url' => $event->snapshot_path ? URL::temporarySignedRoute('detections.media', now()->addHours(12), ['detectionEvent' => $event->id]) : null,
            'champion' => ['alerted' => $verdict->champion_alerted, 'description' => $event->ai_verdict['description'] ?? null],
            'candidate' => ['alerted' => $verdict->alerted, 'description' => $verdict->description, 'confidence' => $verdict->confidence],
            'truth' => $event->ground_truth,
            'truth_source' => $event->ground_truth_source,
        ];
    }
}
