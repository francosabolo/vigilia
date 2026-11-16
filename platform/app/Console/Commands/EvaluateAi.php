<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\DetectionVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Measures the AI verification on a labeled set of images, with the production prompt and models.
 *
 * Set layout:  {path}/positivos/*.jpg  → should alert (person/vehicle where it should not be)
 *              {path}/negativos/*.jpg  → should NOT alert (animals, shadows, rain, guards, empty scene)
 */
class EvaluateAi extends Command
{
    protected $signature = 'seguria:eval
        {path=storage/app/eval : Folder with positivos/ and negativos/}
        {--model= : First-pass model (default: config seguria.ai.model)}
        {--no-review : Do not re-check ambiguous verdicts with the review model}
        {--limit=0 : Max images per class (0 = all)}';

    protected $description = 'Evaluate AI verification on a labeled image set: recall, false alarms, latency and real cost';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        $images = collect(['positivos' => true, 'negativos' => false])
            ->flatMap(fn (bool $isThreat, string $folder) => collect(File::glob("{$path}/{$folder}/*.{jpg,jpeg,png,webp}", GLOB_BRACE))
                ->when((int) $this->option('limit') > 0, fn ($files) => $files->take((int) $this->option('limit')))
                ->map(fn (string $file) => ['file' => $file, 'expected' => $isThreat]));

        if ($images->isEmpty()) {
            $this->error("No images found in {$path}/positivos or {$path}/negativos.");

            return self::FAILURE;
        }
        if (! config('services.anthropic.key')) {
            $this->error('Set ANTHROPIC_API_KEY in platform/.env: the evaluation calls the real model.');

            return self::FAILURE;
        }

        config(['seguria.ai.model' => $this->option('model') ?: config('seguria.ai.model')]);
        if ($this->option('no-review')) {
            config(['seguria.ai.review_band' => [2, 2]]);
        }

        $verifier = app(ClaudeDetectionVerifier::class);
        app()->instance(DetectionVerifier::class, $verifier);
        $camera = new Camera(['name' => 'Cámara de evaluación', 'fov' => 90]);
        $disk = Storage::disk(config('seguria.media_disk'));

        $this->info("Evaluating {$images->count()} images with ".config('seguria.ai.model').($this->option('no-review') ? '' : ' (+ review model for ambiguous cases)').'…');
        $bar = $this->output->createProgressBar($images->count());
        $results = [];

        foreach ($images as $image) {
            $tmp = 'eval-tmp/'.Str::uuid().'.'.pathinfo($image['file'], PATHINFO_EXTENSION);
            $disk->put($tmp, file_get_contents($image['file']));

            $started = microtime(true);
            $verdict = $verifier->verifySnapshot($tmp, $camera, 'Detector: persona (score 0.80). Zonas: alambrado (perímetro). Horario armado: nadie debería estar ahí.');
            $latency = microtime(true) - $started;
            $disk->delete($tmp);

            $alerted = $verdict->isThreat && ($verdict->confidence === null || $verdict->confidence >= config('seguria.ai.threat_threshold'));
            $results[] = [
                'file' => basename($image['file']),
                'expected' => $image['expected'],
                'alerted' => $alerted,
                'confidence' => $verdict->confidence,
                'description' => $verdict->description,
                'latency_s' => round($latency, 2),
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $results = collect($results);
        $tp = $results->where('expected', true)->where('alerted', true)->count();
        $fn = $results->where('expected', true)->where('alerted', false)->count();
        $fp = $results->where('expected', false)->where('alerted', true)->count();
        $tn = $results->where('expected', false)->where('alerted', false)->count();
        $latencies = $results->pluck('latency_s')->sort()->values();
        $cost = collect($verifier->usage)->sum(function (array $usage, string $model) {
            [$in, $out] = config("seguria.ai.prices.{$model}", [0, 0]);

            return ($usage['input'] * $in + $usage['output'] * $out) / 1_000_000;
        });

        $this->table(['Métrica', 'Valor'], [
            ['Intrusiones detectadas (recall)', $tp + $fn ? sprintf('%d de %d (%.1f%%)', $tp, $tp + $fn, 100 * $tp / ($tp + $fn)) : '—'],
            ['Falsas alarmas que pasan', $fp + $tn ? sprintf('%d de %d (%.1f%%)', $fp, $fp + $tn, 100 * $fp / ($fp + $tn)) : '—'],
            ['Latencia mediana / p95', sprintf('%.1f s / %.1f s', $latencies->median(), $latencies->get((int) floor(0.95 * ($latencies->count() - 1))))],
            ['Llamadas por modelo', collect($verifier->usage)->map(fn ($usage, $model) => "{$model}: {$usage['calls']}")->implode(', ')],
            ['Costo real de esta evaluación', sprintf('US$ %.4f', $cost)],
            ['Costo por cada 1.000 verificaciones', sprintf('US$ %.2f', $cost / $results->count() * 1000)],
        ]);

        $errors = $results->filter(fn ($result) => $result['expected'] !== $result['alerted']);
        if ($errors->isNotEmpty()) {
            $this->warn('Errores para revisar:');
            $this->table(['Imagen', 'Esperado', 'Respuesta de la IA'], $errors->map(fn ($result) => [$result['file'], $result['expected'] ? 'alerta' : 'no alerta', Str::limit($result['description'], 90)])->all());
        }

        $reportPath = 'eval/report-'.now()->format('Ymd-His').'.json';
        Storage::put($reportPath, json_encode(['model' => config('seguria.ai.model'), 'usage' => $verifier->usage, 'cost_usd' => $cost, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("Detalle guardado en storage/app/private/{$reportPath}");

        return self::SUCCESS;
    }
}
