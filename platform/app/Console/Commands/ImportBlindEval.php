<?php

namespace App\Console\Commands;

use App\Services\Evaluation\EvalRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Scores the verdicts of a blind console evaluation against its hidden key and saves it as a run,
 * merging the verdicts reused from earlier runs of the same judge and prompt.
 */
class ImportBlindEval extends Command
{
    protected $signature = 'seguria:eval-import {label : Blind run name (folder in storage/app/eval-blind)} {--verdicts= : Path to the verdicts JSON (default: the run folder)}';

    protected $description = 'Score a blind console evaluation and save it as a comparable run';

    public function handle(EvalRuns $runs): int
    {
        $directory = storage_path("app/eval-blind/{$this->argument('label')}");
        $verdictsPath = $this->option('verdicts') ?: "{$directory}/verdicts.json";

        if (! File::exists(storage_path("app/eval-keys/{$this->argument('label')}.json")) || ! File::exists($verdictsPath)) {
            $this->error("Missing key.json or verdicts in {$directory}.");

            return self::FAILURE;
        }

        $key = json_decode(File::get(storage_path("app/eval-keys/{$this->argument('label')}.json")), true);
        $verdicts = collect(json_decode(File::get($verdictsPath), true))->keyBy('img');

        $results = collect($key['images'])->map(function (array $truth, string $img) use ($verdicts) {
            $verdict = $verdicts->get($img);
            $alerted = $verdict && $verdict['is_threat'] && ! ($verdict['suspected_guard'] ?? false) && (float) ($verdict['confidence'] ?? 0) >= config('seguria.ai.threat_threshold');

            return [
                'file' => $truth['file'],
                'sha1' => $truth['sha1'],
                'expected' => $truth['expected'],
                'alerted' => $alerted,
                'confidence' => $verdict['confidence'] ?? null,
                'description' => $verdict['description'] ?? 'Sin respuesta del juez.',
                'cached' => false,
            ];
        })->values();

        // Reuse verdicts of images skipped because the same judge already saw them with this prompt.
        $setFiles = collect(File::glob(base_path($key['set']).'/{positivos,negativos}/*.{jpg,jpeg,png}', GLOB_BRACE))->map(fn ($file) => sha1_file($file))->flip();
        $previous = $runs->all()
            ->filter(fn ($run) => ($run['source'] ?? null) === 'console' && ($run['model'] ?? null) === $key['judge'] && ($run['prompt'] ?? null) === $key['prompt'])
            ->flatMap(fn ($run) => $run['results'])
            ->keyBy('sha1');
        $reused = $previous->filter(fn ($result, $sha1) => $setFiles->has($sha1) && ! $results->contains('sha1', $sha1))
            ->map(fn ($result) => ['cached' => true] + $result)
            ->values();

        $run = $runs->save($this->argument('label'), $results->concat($reused), [
            'source' => 'console',
            'set' => $key['set'],
            'model' => $key['judge'],
            'prompt' => $key['prompt'],
            'cost_usd' => 0,
        ]);

        $metrics = $run['metrics'];
        $this->table(['Métrica', 'Valor'], [
            ['Imágenes (nuevas + reutilizadas)', "{$results->count()} + {$reused->count()}"],
            ['Intrusiones detectadas (recall)', $metrics['recall'] !== null ? sprintf('%.1f%%', 100 * $metrics['recall']) : '—'],
            ['Falsas alarmas que pasan', $metrics['false_alarm_rate'] !== null ? sprintf('%.1f%%', 100 * $metrics['false_alarm_rate']) : '—'],
            ['Errores', $metrics['errors']],
        ]);

        return self::SUCCESS;
    }
}
