<?php

namespace App\Console\Commands;

use App\Models\DetectionEvent;
use App\Models\ShadowVerdict;
use App\Services\Shadow\ShadowMode;
use Illuminate\Console\Command;

/**
 * Runs the challenger now on recent detections that production already decided (instead of waiting for new ones).
 */
class ShadowRun extends Command
{
    protected $signature = 'vigilia:shadow-run
        {--limit=40 : How many detections}
        {--simulated : Also use detections from simulated sites (they have a known ground truth)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Run the shadow-mode challenger on recent detections (paid API calls)';

    /** Rough size of one verification, to estimate the cost before spending. */
    private const ESTIMATED_TOKENS = [1700, 200];

    public function handle(ShadowMode $shadow): int
    {
        if (! config('services.anthropic.key')) {
            $this->error('Set ANTHROPIC_API_KEY: the challenger calls the real model.');

            return self::FAILURE;
        }

        $challenger = $shadow->challenger();
        $done = ShadowVerdict::where('challenger', $challenger['label'])->pluck('detection_event_id');

        $base = fn () => DetectionEvent::query()
            ->with('camera.site')
            ->whereNotNull('ai_verdict')
            ->whereNotNull('snapshot_path')
            ->whereNotIn('id', $done)
            ->when(! $this->option('simulated'), fn ($query) => $query->whereHas('camera.site', fn ($site) => $site->where('is_simulated', false)))
            ->inRandomOrder();

        // Intrusions are rare: a random sample would bring almost none. Half the sample are known threats or
        // what production alerted; the other half, the rest.
        $limit = (int) $this->option('limit');
        $interesting = $base()->where(fn ($query) => $query->where('ground_truth', true)->orWhereNotNull('incident_id'))->limit(intdiv($limit, 2))->get();
        $events = $interesting->concat($base()->whereNotIn('id', $interesting->pluck('id'))->limit($limit - $interesting->count())->get());

        if ($events->isEmpty()) {
            $this->warn('No detections to compare'.($this->option('simulated') ? '.' : ' (try --simulated).'));

            return self::SUCCESS;
        }

        [$in, $out] = config("vigilia.ai.prices.{$challenger['model']}", [0, 0]);
        $estimate = $events->count() * (self::ESTIMATED_TOKENS[0] * $in + self::ESTIMATED_TOKENS[1] * $out) / 1_000_000;
        $this->line("Retador: {$challenger['label']}. {$events->count()} detecciones, costo estimado US$ ".number_format($estimate, 3).'.');

        if (! $this->option('force') && ! $this->confirm('¿Seguir?', true)) {
            return self::SUCCESS;
        }

        $verdicts = collect();
        $this->withProgressBar($events, function (DetectionEvent $event) use ($shadow, $verdicts) {
            $verdicts->push($shadow->run($event));
        });
        $this->newLine(2);

        $verdicts = $verdicts->filter();
        $this->info(sprintf('%d veredictos, %d desacuerdos con producción, costo real US$ %.4f. Miralos en /ai/shadow.',
            $verdicts->count(), $verdicts->filter(fn ($verdict) => $verdict->alerted !== $verdict->champion_alerted)->count(), $verdicts->sum('cost_usd')));

        return self::SUCCESS;
    }
}
