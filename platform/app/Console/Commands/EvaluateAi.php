<?php

namespace App\Console\Commands;

use Anthropic\Client;
use Anthropic\Lib\Helpers\StructuredOutput;
use App\Models\Camera;
use App\Services\Evaluation\EvalRuns;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\VerdictCache;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Measures the AI verification on a labeled set of images, with the production prompt and models.
 *
 * Set layout:  {path}/positivos/*.jpg  → should alert (person/vehicle where it should not be)
 *              {path}/negativos/*.jpg  → should NOT alert (animals, shadows, rain, guards, empty scene)
 *
 * Cheap by default: verdicts are cached (image + model + prompt version), so re-running only pays for what
 * changed; --batch sends the uncached calls through the Message Batches API at half price.
 */
class EvaluateAi extends Command
{
    protected $signature = 'seguria:eval
        {path=storage/app/eval : Folder with positivos/ and negativos/}
        {--model= : First-pass model (default: config seguria.ai.model)}
        {--no-review : Do not re-check ambiguous verdicts with the review model}
        {--batch : Send uncached calls through the Message Batches API (50% cheaper, minutes instead of seconds)}
        {--fresh : Ignore cached verdicts and pay for every call again}
        {--label= : Name for this run in seguria:eval-compare}
        {--limit=0 : Max images per class (0 = all)}';

    protected $description = 'Evaluate AI verification on a labeled image set: recall, false alarms, latency and real cost';

    private const CONTEXT = 'Detector: persona (score 0.80). Zonas: alambrado (perímetro). Horario armado: nadie debería estar ahí.';

    public function handle(EvalRuns $runs): int
    {
        $images = $this->images(base_path($this->argument('path')));

        if ($images->isEmpty()) {
            $this->error('No images found in '.base_path($this->argument('path')).'/positivos or negativos.');

            return self::FAILURE;
        }
        if (! config('services.anthropic.key')) {
            $this->error('Set ANTHROPIC_API_KEY in platform/.env: the evaluation calls the real model.');

            return self::FAILURE;
        }

        $model = $this->option('model') ?: config('seguria.ai.model');
        config(['seguria.ai.model' => $model]);
        if ($this->option('no-review')) {
            config(['seguria.ai.review_band' => [2, 2]]);
        }

        $verifier = app(ClaudeDetectionVerifier::class);
        $verifier->cache = $this->option('fresh') ? null : app(VerdictCache::class);
        app()->instance(DetectionVerifier::class, $verifier);
        $camera = new Camera(['name' => 'Cámara de evaluación', 'fov' => 90]);
        $disk = Storage::disk(config('seguria.media_disk'));

        $batchUsage = [];
        if ($this->option('batch') && $verifier->cache) {
            $batchUsage = $this->prefillWithBatch($verifier, $images, $camera, $model);
            [$low, $high] = config('seguria.ai.review_band');
            if ($low <= 1) {
                $batchUsage = array_merge_recursive($batchUsage, $this->prefillWithBatch($verifier, $images, $camera, config('seguria.ai.review_model'), $low, $high, $model));
            }
        }

        $cache = app(VerdictCache::class);
        $this->info("Evaluating {$images->count()} images with {$model}".($this->option('no-review') ? '' : ' (+ '.config('seguria.ai.review_model').' for ambiguous cases)').'…');
        $bar = $this->output->createProgressBar($images->count());
        $results = collect();

        foreach ($images as $image) {
            $tmp = 'eval-tmp/'.Str::uuid().'.'.pathinfo($image['file'], PATHINFO_EXTENSION);
            $disk->put($tmp, file_get_contents($image['file']));
            $prepared = $verifier->prepareSnapshot($tmp, $camera, self::CONTEXT);
            $cachedBefore = $prepared && $cache->has($cache->key($model, ClaudeDetectionVerifier::promptFingerprint(), $prepared['context'], $prepared['image']['data']));

            $started = microtime(true);
            $verdict = $verifier->verifySnapshot($tmp, $camera, self::CONTEXT);
            $latency = microtime(true) - $started;
            $disk->delete($tmp);

            $results->push([
                'file' => Str::after($image['file'], base_path().'/'),
                'sha1' => sha1_file($image['file']),
                'expected' => $image['expected'],
                'alerted' => $verdict->isThreat && ($verdict->confidence === null || $verdict->confidence >= config('seguria.ai.threat_threshold')),
                'confidence' => $verdict->confidence,
                'description' => $verdict->description,
                'cached' => $cachedBefore && ! $this->option('fresh'),
                'latency_s' => round($latency, 2),
            ]);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $liveCost = $this->cost($verifier->usage, 1.0);
        $batchCost = $this->cost($batchUsage, 0.5);
        $fresh = $results->where('cached', false);
        $latencies = $fresh->pluck('latency_s')->sort()->values();
        $label = $this->option('label') ?: "{$model}".($this->option('no-review') ? ' sin revisión' : '').' · '.basename($this->argument('path'));

        $run = $runs->save($label, $results, [
            'source' => 'api',
            'set' => $this->argument('path'),
            'model' => $model,
            'review_model' => $this->option('no-review') ? null : config('seguria.ai.review_model'),
            'prompt' => ClaudeDetectionVerifier::promptFingerprint(),
            'cost_usd' => round($liveCost + $batchCost, 4),
        ]);
        $metrics = $run['metrics'];

        $this->table(['Métrica', 'Valor'], [
            ['Intrusiones detectadas (recall)', $metrics['recall'] !== null ? sprintf('%.1f%% de %d', 100 * $metrics['recall'], $metrics['positives']) : '—'],
            ['Falsas alarmas que pasan', $metrics['false_alarm_rate'] !== null ? sprintf('%.1f%% de %d', 100 * $metrics['false_alarm_rate'], $metrics['negatives']) : '—'],
            ['Veredictos reutilizados de la caché', sprintf('%d de %d (no se pagaron)', $results->where('cached', true)->count(), $results->count())],
            ['Latencia mediana / p95 (llamadas en vivo)', $latencies->isEmpty() ? '— (todo desde caché o batch)' : sprintf('%.1f s / %.1f s', $latencies->median(), $latencies->get((int) floor(0.95 * ($latencies->count() - 1))))],
            ['Costo de esta corrida', sprintf('US$ %.4f (en vivo %.4f + batch %.4f)', $liveCost + $batchCost, $liveCost, $batchCost)],
        ]);

        $errors = $results->filter(fn ($result) => $result['expected'] !== $result['alerted']);
        if ($errors->isNotEmpty()) {
            $this->warn('Errores para revisar:');
            $this->table(['Imagen', 'Esperado', 'Respuesta de la IA'], $errors->map(fn ($result) => [basename($result['file']), $result['expected'] ? 'alerta' : 'no alerta', Str::limit($result['description'], 90)])->all());
        }
        $this->line("Corrida guardada como \"{$label}\". Compará corridas con: php artisan seguria:eval-compare");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{file: string, expected: bool}>
     */
    private function images(string $path): Collection
    {
        return collect(['positivos' => true, 'negativos' => false])
            ->flatMap(fn (bool $isThreat, string $folder) => collect(File::glob("{$path}/{$folder}/*.{jpg,jpeg,png,webp}", GLOB_BRACE))
                ->when((int) $this->option('limit') > 0, fn ($files) => $files->take((int) $this->option('limit')))
                ->map(fn (string $file) => ['file' => $file, 'expected' => $isThreat]))
            ->values();
    }

    /**
     * Sends every uncached call for $model in one Message Batch and stores the verdicts in the cache.
     * For the review model, only images whose first-pass confidence falls in the review band are sent.
     *
     * @return array<string, array{calls: int, input: int, output: int}>
     */
    private function prefillWithBatch(ClaudeDetectionVerifier $verifier, Collection $images, Camera $camera, string $model, float $low = 0, float $high = 0, ?string $firstPassModel = null): array
    {
        $cache = $verifier->cache;
        $disk = Storage::disk(config('seguria.media_disk'));
        $pending = [];

        foreach ($images as $index => $image) {
            $tmp = 'eval-tmp/'.Str::uuid().'.'.pathinfo($image['file'], PATHINFO_EXTENSION);
            $disk->put($tmp, file_get_contents($image['file']));
            $prepared = $verifier->prepareSnapshot($tmp, $camera, self::CONTEXT);
            $disk->delete($tmp);
            if (! $prepared) {
                continue;
            }

            if ($firstPassModel) {
                $first = $cache->get($cache->key($firstPassModel, ClaudeDetectionVerifier::promptFingerprint(), $prepared['context'], $prepared['image']['data']));
                $confidence = $first['verdict']['confidence'] ?? null;
                if ($confidence === null || $confidence < $low || $confidence > $high) {
                    continue;
                }
            }

            $key = $cache->key($model, ClaudeDetectionVerifier::promptFingerprint(), $prepared['context'], $prepared['image']['data']);
            if (! $cache->has($key)) {
                $params = $verifier->requestParams($model, $prepared['context'], $prepared['image']);
                StructuredOutput::distillInputSchemas($params);
                $pending["img-{$index}"] = ['key' => $key, 'params' => $params];
            }
        }

        if ($pending === []) {
            return [];
        }

        $client = app(Client::class);
        $this->info(sprintf('Sending %d calls to %s through the Batches API (50%% cheaper)…', count($pending), $model));
        $batch = $client->messages->batches->create(requests: collect($pending)->map(fn ($item, $id) => ['customID' => $id, 'params' => $item['params']])->values()->all());

        while ($batch->processingStatus !== 'ended') {
            sleep(15);
            $batch = $client->messages->batches->retrieve($batch->id);
            $this->line(sprintf('  batch: %d done, %d processing', $batch->requestCounts->succeeded + $batch->requestCounts->errored, $batch->requestCounts->processing));
        }

        $usage = [$model => ['calls' => 0, 'input' => 0, 'output' => 0]];
        foreach ($client->messages->batches->resultsStream($batch->id) as $response) {
            if ($response->result->type !== 'succeeded' || ! isset($pending[$response->customID])) {
                continue;
            }
            $message = $response->result->message;
            $verdict = $verifier->verdictFromMessage($message, $model);
            $tokens = ['input' => $message->usage->inputTokens, 'output' => $message->usage->outputTokens];
            $usage[$model]['calls']++;
            $usage[$model]['input'] += $tokens['input'];
            $usage[$model]['output'] += $tokens['output'];
            if ($verdict) {
                $cache->put($pending[$response->customID]['key'], $verdict, $tokens, batch: true);
            }
        }

        return $usage;
    }

    /**
     * @param  array<string, array{input: int|array<int,int>, output: int|array<int,int>}>  $usage
     */
    private function cost(array $usage, float $factor): float
    {
        return collect($usage)->map(function (array $tokens, string $model) use ($factor) {
            [$in, $out] = config("seguria.ai.prices.{$model}", [0, 0]);

            return (array_sum((array) $tokens['input']) * $in + array_sum((array) $tokens['output']) * $out) / 1_000_000 * $factor;
        })->sum();
    }
}
