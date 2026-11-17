<?php

namespace App\Console\Commands;

use App\Services\Evaluation\EvalRuns;
use App\Services\Verification\ClaudeDetectionVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Prepares a blind copy of an evaluation set to be judged by a Claude console session (no API cost):
 * shuffled anonymous file names, a hidden answer key, and the exact instructions to give the judge.
 * Images already judged with the same prompt version are skipped and reused at import time.
 */
class BlindEvalSet extends Command
{
    protected $signature = 'vigilia:eval-blind
        {path=storage/app/eval : Folder with positivos/ and negativos/}
        {--label= : Name of this blind run (default: folder name)}
        {--judge=consola-opus : Who judges (to reuse previous verdicts of the same judge)}
        {--all : Do not skip images already judged with the same prompt}';

    protected $description = 'Prepare a blind, shuffled copy of an evaluation set to be judged in a Claude console session';

    public function handle(EvalRuns $runs): int
    {
        $source = base_path($this->argument('path'));
        $label = Str::slug($this->option('label') ?: basename($source));
        $directory = storage_path("app/eval-blind/{$label}");
        $prompt = ClaudeDetectionVerifier::promptFingerprint();

        $judged = $this->option('all') ? collect() : $runs->all()
            ->filter(fn ($run) => ($run['source'] ?? null) === 'console' && ($run['model'] ?? null) === $this->option('judge') && ($run['prompt'] ?? null) === $prompt)
            ->flatMap(fn ($run) => collect($run['results'])->pluck('sha1'))
            ->unique()
            ->flip();

        $all = collect(['positivos' => true, 'negativos' => false])
            ->flatMap(fn (bool $expected, string $class) => collect(File::glob("{$source}/{$class}/*.{jpg,jpeg,png}", GLOB_BRACE))->map(fn ($file) => ['file' => $file, 'expected' => $expected, 'sha1' => sha1_file($file)]))
            ->values();
        $files = $all->reject(fn ($image) => $judged->has($image['sha1']))->shuffle()->values();

        File::deleteDirectory($directory);
        File::ensureDirectoryExists("{$directory}/imagenes");
        File::ensureDirectoryExists(storage_path('app/eval-keys')); // the answer key never lives next to the images

        $key = [];
        foreach ($files as $i => $image) {
            $name = sprintf('img_%03d.%s', $i + 1, pathinfo($image['file'], PATHINFO_EXTENSION));
            File::copy($image['file'], "{$directory}/imagenes/{$name}");
            $key[$name] = ['file' => Str::after($image['file'], base_path().'/'), 'sha1' => $image['sha1'], 'expected' => $image['expected']];
        }

        File::put(storage_path("app/eval-keys/{$label}.json"), json_encode(['set' => $this->argument('path'), 'judge' => $this->option('judge'), 'prompt' => $prompt, 'images' => $key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put("{$directory}/INSTRUCCIONES.md", $this->instructions("{$directory}/imagenes", "{$directory}/verdicts.json"));

        $this->info(sprintf('%d images ready in %s (%d skipped: already judged with this prompt, reused at import).', $files->count(), "{$directory}/imagenes", $all->count() - $files->count()));
        $this->line("Give a console session (or subagent) the instructions in {$directory}/INSTRUCCIONES.md — never the key.");
        $this->line("Then: php artisan vigilia:eval-import {$label}");

        return self::SUCCESS;
    }

    private function instructions(string $images, string $output): string
    {
        $prompt = ClaudeDetectionVerifier::SYSTEM_PROMPT;

        return <<<MD
        # Evaluación a ciegas

        Judge the security camera frames in `{$images}` BLIND: open every image with the Read tool and judge only
        from the pixels. Do not open anything else in the project (in particular never look for an answer key).

        Criterion (production prompt, Spanish):

        ```
        {$prompt}
        ```

        Context for every frame: "Cámara: Cámara de evaluación. Detector: persona (score 0.80). Zonas: alambrado (perímetro). Horario armado: nadie debería estar ahí."

        Write a JSON array to `{$output}` with one object per image, in file name order:
        `{"img": "img_001.jpg", "is_threat": true|false, "confidence": 0..1, "suspected_guard": true|false, "description": "una oración para la guardia"}`.
        Reply only with the number of images judged.
        MD;
    }
}
