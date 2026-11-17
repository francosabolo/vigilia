<?php

namespace App\Services\Activity;

use App\Enums\DetectionStatus;
use App\Models\DetectionEvent;
use App\Services\Verification\LabelTranslator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Presents what the system is doing with each detection in human language, with the full decision trail.
 * Used by the live "La IA ahora" feed and by "Cómo se decidió" in incidents.
 */
class ActivityFeed
{
    /**
     * @param  array<int, int>  $siteIds
     * @return Collection<int, array<string, mixed>>
     */
    public function latest(array $siteIds, int $limit = 25): Collection
    {
        return DetectionEvent::query()
            ->with('camera.site:id,name')
            ->whereHas('camera', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->where('status', '!=', DetectionStatus::Pending)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (DetectionEvent $event) => $this->item($event));
    }

    /**
     * @return array<string, mixed>
     */
    public function item(DetectionEvent $event): array
    {
        $event->loadMissing('camera.site:id,name');
        $label = LabelTranslator::translate($event->label);
        $verdict = $event->ai_verdict ?? [];
        $where = $event->camera->name;

        [$outcome, $headline] = match ($event->status) {
            DetectionStatus::Ignored => ['ignored', match ($verdict['skipped'] ?? null) {
                'zone_disarmed' => "No la analizó: {$where} no está armada a esta hora.",
                'outside_watched_zone' => 'No la analizó: '.LabelTranslator::withArticle($event->label).' estaba fuera de la zona vigilada.',
                default => 'No la analizó por las reglas del barrio.',
            }],
            DetectionStatus::Dismissed => ['dismissed', 'Descartada: '.$this->sentence($verdict['description'] ?? 'sin amenaza visible')],
            DetectionStatus::Threat => ['alert', 'Alerta: '.$this->sentence($verdict['description'] ?? "{$label} en {$where}")],
            default => ['analyzing', 'Mirando '.LabelTranslator::withArticle($event->label, true)." en {$where}…"],
        };

        return [
            'id' => $event->id,
            'site' => $event->camera->site->name,
            'camera' => $where,
            'label' => $label,
            'outcome' => $outcome,
            'headline' => $headline,
            'agent' => $this->agentName($verdict['model'] ?? null, $outcome),
            'confidence' => $verdict['confidence'] ?? null,
            'incident_id' => $event->incident_id,
            'at' => ($event->updated_at ?? $event->started_at)->toIso8601String(),
            'snapshot_url' => $event->snapshot_path ? URL::temporarySignedRoute('detections.media', now()->addHours(12), ['detectionEvent' => $event->id]) : null,
            'frame_urls' => collect($event->frame_paths ?? [])->keys()
                ->map(fn (int $index) => URL::temporarySignedRoute('detections.media', now()->addHours(12), ['detectionEvent' => $event->id, 'frame' => $index]))
                ->all(),
            'trace' => $event->trace ?? [],
        ];
    }

    /**
     * The "analyzing" state, sent before the AI answers.
     *
     * @return array<string, mixed>
     */
    public function analyzing(DetectionEvent $event): array
    {
        return ['outcome' => 'analyzing', 'headline' => sprintf('Mirando %s en %s…', LabelTranslator::withArticle($event->label, true), $event->camera->name), 'agent' => 'Analista IA'] + $this->item($event);
    }

    public function agentName(?string $model, string $outcome = 'alert'): string
    {
        if ($outcome === 'ignored') {
            return 'Reglas del barrio';
        }

        return match (true) {
            $model === null => 'Analista IA',
            str_contains($model, 'haiku') => 'Analista IA (Haiku)',
            str_contains($model, 'sonnet') => 'Revisora IA (Sonnet)',
            str_contains($model, 'opus') => 'Analista IA (Opus)',
            $model === 'simulado' => 'Analista IA (simulada)',
            default => "Analista IA ({$model})",
        };
    }

    private function sentence(string $text): string
    {
        $text = trim($text);

        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
