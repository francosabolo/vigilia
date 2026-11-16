<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\VerdictCache;
use App\Services\Verification\VerificationResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Evaluation as a release gate. The repo keeps a manifest (image hash + expected answer) and the verdicts the
 * current prompt and models gave, so CI recomputes the metrics offline: no API calls, no images.
 *
 * It fails when the prompt, model or context changed without re-recording (there are no verdicts for them), or
 * when a set falls below its thresholds. --record re-evaluates locally (paid, cached) and refuses to save a
 * regression unless --accept-regression.
 */
class EvalGate extends Command
{
    protected $signature = 'seguria:eval-gate
        {--record : Re-run the evaluation with the API and update the committed verdicts}
        {--accept-regression : Save the recording even if a metric got worse}
        {--dir=evals : Folder with gate.json, manifest.json and verdicts/}';

    protected $description = 'Check (offline) that the AI still meets the recall and false-alarm thresholds; --record to re-evaluate';

    public function handle(ClaudeDetectionVerifier $verifier): int
    {
        $dir = base_path($this->option('dir'));
        $gate = json_decode(File::get("{$dir}/gate.json"), true);

        return $this->option('record') ? $this->record($dir, $gate, $verifier) : $this->check($dir, $gate, $verifier);
    }

    private function check(string $dir, array $gate, ClaudeDetectionVerifier $verifier): int
    {
        if (! File::exists("{$dir}/manifest.json")) {
            $this->error('There is no recording yet. Run: php artisan seguria:eval-gate --record');

            return self::FAILURE;
        }

        $manifest = json_decode(File::get("{$dir}/manifest.json"), true);
        $context = $verifier->contextFor($this->camera(), EvaluateAi::CONTEXT);
        $fingerprint = ClaudeDetectionVerifier::promptFingerprint();
        $failed = false;
        $rows = [];

        foreach ($gate['sets'] as $set => $limits) {
            $limits += $gate['defaults'];
            $images = collect($manifest['images'])->where('set', $set);
            $missing = 0;
            $results = $images->map(function (array $image) use ($dir, $context, $fingerprint, &$missing) {
                $verdict = $this->decide(fn (string $model) => $this->verdict($dir, $model, $fingerprint, $context, $image['sha1']));
                if ($verdict === null) {
                    $missing++;

                    return null;
                }

                return ['expected' => $image['expected'], 'alerted' => $this->alerted($verdict)];
            })->filter();

            if ($images->isEmpty() || $missing > 0) {
                $failed = true;
                $rows[] = [$set, '—', '—', $images->isEmpty() ? 'Sin imágenes en el manifiesto' : "Faltan {$missing} veredictos: cambió el prompt, el modelo o el contexto"];

                continue;
            }

            $metrics = $this->metrics($results->all());
            $problems = array_filter([
                $metrics['recall'] < $limits['min_recall'] ? sprintf('recall < %.0f%%', 100 * $limits['min_recall']) : null,
                $metrics['false_alarm_rate'] > $limits['max_false_alarm_rate'] ? sprintf('falsas alarmas > %.0f%%', 100 * $limits['max_false_alarm_rate']) : null,
            ]);
            $failed = $failed || $problems !== [];
            $rows[] = [$set, sprintf('%.1f%%', 100 * $metrics['recall']), sprintf('%.1f%%', 100 * $metrics['false_alarm_rate']), $problems === [] ? 'OK' : implode(', ', $problems)];
        }

        $this->table(['Set', 'Recall', 'Falsas alarmas', 'Resultado'], $rows);
        $this->line("Prompt {$fingerprint}, modelo ".config('seguria.ai.model').' + revisión '.config('seguria.ai.review_model').'.');

        if ($failed) {
            $this->error('Evaluation gate failed. If you changed the prompt or the models, run locally: php artisan seguria:eval-gate --record');

            return self::FAILURE;
        }

        $this->info('Evaluation gate passed.');

        return self::SUCCESS;
    }

    private function record(string $dir, array $gate, ClaudeDetectionVerifier $verifier): int
    {
        $cache = app(VerdictCache::class);
        $context = $verifier->contextFor($this->camera(), EvaluateAi::CONTEXT);
        $fingerprint = ClaudeDetectionVerifier::promptFingerprint();
        $previous = File::exists("{$dir}/manifest.json") ? json_decode(File::get("{$dir}/manifest.json"), true) : null;
        $images = [];
        $verdicts = [];
        $metrics = [];

        foreach (array_keys($gate['sets']) as $set) {
            if ($this->call('seguria:eval', ['path' => $set, '--batch' => true, '--label' => "gate {$fingerprint} ".basename($set)]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            $results = [];
            foreach (['positivos' => true, 'negativos' => false] as $folder => $expected) {
                foreach (File::glob(base_path($set)."/{$folder}/*.{jpg,jpeg,png,webp}", GLOB_BRACE) as $file) {
                    $hash = sha1(base64_encode(File::get($file)));
                    $verdict = $this->decide(function (string $model) use ($cache, $fingerprint, $context, $hash, &$verdicts) {
                        $key = VerdictCache::keyFromImageHash($model, $fingerprint, $context, $hash);
                        $entry = $cache->get($key);
                        if ($entry) {
                            $verdicts[$key] = $cache->pathFor($key);
                        }

                        return $entry ? VerificationResult::fromArray($entry['verdict']) : null;
                    });
                    if ($verdict === null) {
                        $this->error("No verdict for {$file}: the evaluation did not complete.");

                        return self::FAILURE;
                    }

                    $images[] = ['set' => $set, 'file' => $folder.'/'.basename($file), 'sha1' => $hash, 'expected' => $expected];
                    $results[] = ['expected' => $expected, 'alerted' => $this->alerted($verdict)];
                }
            }
            $metrics[$set] = $this->metrics($results);
        }

        $regressions = collect($metrics)->filter(function (array $now, string $set) use ($previous) {
            $before = $previous['metrics'][$set] ?? null;

            return $before && ($now['recall'] < $before['recall'] || $now['false_alarm_rate'] > $before['false_alarm_rate'] + 0.02);
        });
        if ($regressions->isNotEmpty() && ! $this->option('accept-regression')) {
            $this->table(['Set', 'Recall antes → ahora', 'Falsas alarmas antes → ahora'], $regressions->map(fn ($now, $set) => [
                $set,
                sprintf('%.1f%% → %.1f%%', 100 * $previous['metrics'][$set]['recall'], 100 * $now['recall']),
                sprintf('%.1f%% → %.1f%%', 100 * $previous['metrics'][$set]['false_alarm_rate'], 100 * $now['false_alarm_rate']),
            ])->values()->all());
            $this->error('This change makes the AI worse. Nothing was saved (use --accept-regression to save it anyway).');

            return self::FAILURE;
        }

        File::deleteDirectory("{$dir}/verdicts");
        File::ensureDirectoryExists("{$dir}/verdicts");
        foreach ($verdicts as $key => $path) {
            File::copy($path, "{$dir}/verdicts/{$key}.json");
        }
        File::put("{$dir}/manifest.json", json_encode([
            'recorded_at' => now()->toIso8601String(),
            'prompt' => $fingerprint,
            'model' => config('seguria.ai.model'),
            'review_model' => config('seguria.ai.review_model'),
            'metrics' => $metrics,
            'images' => $images,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info(sprintf('Recorded %d images and %d verdicts. Commit the %s folder.', count($images), count($verdicts), $this->option('dir')));

        return $this->check($dir, $gate, $verifier);
    }

    /**
     * Same decision as production: first-pass model, and the review model for the ambiguous band.
     *
     * @param  callable(string): ?VerificationResult  $lookup
     */
    private function decide(callable $lookup): ?VerificationResult
    {
        $verdict = $lookup(config('seguria.ai.model'));
        [$low, $high] = config('seguria.ai.review_band');

        if ($verdict && $verdict->confidence !== null && $verdict->confidence >= $low && $verdict->confidence <= $high) {
            return $lookup(config('seguria.ai.review_model'));
        }

        return $verdict;
    }

    private function verdict(string $dir, string $model, string $fingerprint, string $context, string $hash): ?VerificationResult
    {
        $file = "{$dir}/verdicts/".VerdictCache::keyFromImageHash($model, $fingerprint, $context, $hash).'.json';

        return File::exists($file) ? VerificationResult::fromArray(json_decode(File::get($file), true)['verdict']) : null;
    }

    private function alerted(VerificationResult $verdict): bool
    {
        return $verdict->isThreat && ($verdict->confidence === null || $verdict->confidence >= config('seguria.ai.threat_threshold'));
    }

    /**
     * @param  array<int, array{expected: bool, alerted: bool}>  $results
     * @return array{recall: float, false_alarm_rate: float}
     */
    private function metrics(array $results): array
    {
        $positives = collect($results)->where('expected', true);
        $negatives = collect($results)->where('expected', false);

        return [
            'recall' => $positives->isEmpty() ? 1.0 : round($positives->where('alerted', true)->count() / $positives->count(), 4),
            'false_alarm_rate' => $negatives->isEmpty() ? 0.0 : round($negatives->where('alerted', true)->count() / $negatives->count(), 4),
        ];
    }

    private function camera(): Camera
    {
        return new Camera(['name' => 'Cámara de evaluación', 'fov' => 90]);
    }
}
