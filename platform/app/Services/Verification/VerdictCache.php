<?php

namespace App\Services\Verification;

use Illuminate\Support\Facades\Storage;

/**
 * File cache of AI verdicts for evaluations, keyed by model + prompt version + context + image content.
 * Re-running an evaluation only pays for what changed (new images, another model, a new prompt).
 */
class VerdictCache
{
    private const DIRECTORY = 'eval-cache';

    public function key(string $model, string $promptFingerprint, string $context, string $imageData): string
    {
        return sha1(implode('|', [$model, $promptFingerprint, $context, sha1($imageData)]));
    }

    /**
     * @return array{model: string, prompt: string, verdict: array<string, mixed>, usage: array{input: int, output: int}, batch: bool, created_at: string}|null
     */
    public function get(string $key): ?array
    {
        $path = $this->path($key);

        return Storage::exists($path) ? json_decode(Storage::get($path), true) : null;
    }

    /**
     * @param  array{input: int, output: int}  $usage
     */
    public function put(string $key, VerificationResult $result, array $usage, bool $batch): void
    {
        Storage::put($this->path($key), json_encode([
            'model' => $result->model,
            'prompt' => ClaudeDetectionVerifier::promptFingerprint(),
            'verdict' => $result->toArray(),
            'usage' => $usage,
            'batch' => $batch,
            'created_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE));
    }

    public function has(string $key): bool
    {
        return Storage::exists($this->path($key));
    }

    private function path(string $key): string
    {
        return self::DIRECTORY.'/'.substr($key, 0, 2).'/'.$key.'.json';
    }
}
