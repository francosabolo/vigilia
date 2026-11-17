<?php

namespace App\Services\Shadow;

use App\Jobs\RunShadowVerification;
use App\Models\DetectionEvent;
use App\Models\ShadowVerdict;
use App\Services\Verification\ClaudeDetectionVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a challenger (another model and/or prompt) next to production on a sample of real detections. It never
 * decides anything: it only records what it would have said, so it can be compared before promoting it.
 */
class ShadowMode
{
    public function enabled(): bool
    {
        return (bool) config('vigilia.ai.shadow.enabled');
    }

    /**
     * @return array{label: string, model: string, prompt: string, fingerprint: string}
     */
    public function challenger(): array
    {
        $file = config('vigilia.ai.shadow.prompt_file');
        $prompt = $file ? trim(File::get(base_path($file))) : ClaudeDetectionVerifier::SYSTEM_PROMPT;
        $fingerprint = ClaudeDetectionVerifier::promptFingerprint($prompt);
        $model = config('vigilia.ai.shadow.model') ?: config('vigilia.ai.model');

        return ['label' => "{$model}, prompt {$fingerprint}", 'model' => $model, 'prompt' => $prompt, 'fingerprint' => $fingerprint];
    }

    /**
     * Called by the pipeline after production decided. Samples, respects the daily cap and queues the challenger.
     */
    public function consider(DetectionEvent $event): void
    {
        if (! $this->enabled() || ! $event->snapshot_path || $event->ai_verdict === null) {
            return;
        }
        if ($event->camera->site->is_simulated && ! config('vigilia.ai.shadow.include_simulated')) {
            return;
        }
        if (mt_rand() / mt_getrandmax() > config('vigilia.ai.shadow.sample_rate')) {
            return;
        }

        $key = 'shadow:count:'.now()->toDateString();
        Cache::add($key, 0, now()->endOfDay()->addHour());
        if (Cache::increment($key) > config('vigilia.ai.shadow.daily_cap')) {
            return;
        }

        RunShadowVerification::dispatch($event->id);
    }

    /**
     * Asks the challenger about one detection and stores its answer. Failures are logged, never raised:
     * shadow mode must not disturb production.
     */
    public function run(DetectionEvent $event): ?ShadowVerdict
    {
        $challenger = $this->challenger();

        if (ShadowVerdict::where('detection_event_id', $event->id)->where('challenger', $challenger['label'])->exists()) {
            return null;
        }

        try {
            $answer = app(ClaudeDetectionVerifier::class)->challenge($event, $challenger['model'], $challenger['prompt']);
        } catch (Throwable $exception) {
            Log::warning('Shadow verification failed', ['detection_event_id' => $event->id, 'error' => $exception->getMessage()]);

            return null;
        }

        if ($answer === null) {
            return null;
        }

        $result = $answer['result'];
        [$in, $out] = config("vigilia.ai.prices.{$challenger['model']}", [0, 0]);

        return ShadowVerdict::create([
            'detection_event_id' => $event->id,
            'challenger' => $challenger['label'],
            'model' => $challenger['model'],
            'prompt' => $challenger['fingerprint'],
            'champion_alerted' => $event->incident_id !== null,
            'alerted' => $result->isThreat && ($result->confidence === null || $result->confidence >= config('vigilia.ai.threat_threshold')),
            'is_threat' => $result->isThreat,
            'confidence' => $result->confidence,
            'description' => $result->description,
            'input_tokens' => $answer['input_tokens'],
            'output_tokens' => $answer['output_tokens'],
            'cost_usd' => ($answer['input_tokens'] * $in + $answer['output_tokens'] * $out) / 1_000_000,
            'latency_ms' => $answer['latency_ms'],
        ]);
    }
}
