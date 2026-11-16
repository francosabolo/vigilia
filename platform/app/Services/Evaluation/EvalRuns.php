<?php

namespace App\Services\Evaluation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Every evaluation (API, batch or console) is saved in the same format, keyed by image content (sha1),
 * so runs can be compared later without paying for them again.
 */
class EvalRuns
{
    private const DIRECTORY = 'eval-runs';

    /**
     * @param  Collection<int, array{file: string, sha1: string, expected: bool, alerted: bool, confidence: ?float, description: string, cached?: bool}>  $results
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function save(string $label, Collection $results, array $meta): array
    {
        $run = ['label' => $label, 'created_at' => now()->toIso8601String(), ...$meta, 'metrics' => $this->metrics($results), 'results' => $results->values()->all()];
        Storage::put(self::DIRECTORY.'/'.now()->format('Ymd-His').'-'.Str::slug($label).'.json', json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $run;
    }

    /**
     * @param  Collection<int, array{expected: bool, alerted: bool}>  $results
     * @return array{images: int, positives: int, negatives: int, recall: ?float, false_alarm_rate: ?float, errors: int}
     */
    public function metrics(Collection $results): array
    {
        $positives = $results->where('expected', true);
        $negatives = $results->where('expected', false);

        return [
            'images' => $results->count(),
            'positives' => $positives->count(),
            'negatives' => $negatives->count(),
            'recall' => $positives->isEmpty() ? null : round($positives->where('alerted', true)->count() / $positives->count(), 4),
            'false_alarm_rate' => $negatives->isEmpty() ? null : round($negatives->where('alerted', true)->count() / $negatives->count(), 4),
            'errors' => $results->filter(fn ($result) => $result['expected'] !== $result['alerted'])->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return collect(Storage::files(self::DIRECTORY))
            ->filter(fn (string $path) => str_ends_with($path, '.json'))
            ->sort()
            ->map(fn (string $path) => json_decode(Storage::get($path), true) + ['path' => $path])
            ->values();
    }
}
