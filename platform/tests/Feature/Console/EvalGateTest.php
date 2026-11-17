<?php

use App\Console\Commands\EvaluateAi;
use App\Models\Camera;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\VerdictCache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['vigilia.ai.model' => 'haiku', 'vigilia.ai.review_model' => 'sonnet', 'vigilia.ai.review_band' => [0.35, 0.75], 'vigilia.ai.threat_threshold' => 0.5]);
    $this->dir = 'storage/framework/testing/gate-'.uniqid();
    File::ensureDirectoryExists(base_path("{$this->dir}/verdicts"));
    File::put(base_path("{$this->dir}/gate.json"), json_encode(['defaults' => ['min_recall' => 1.0, 'max_false_alarm_rate' => 0.0], 'sets' => ['set' => []]]));
    File::put(base_path("{$this->dir}/manifest.json"), json_encode(['images' => [
        ['set' => 'set', 'file' => 'positivos/a.jpg', 'sha1' => 'aaa', 'expected' => true],
        ['set' => 'set', 'file' => 'negativos/b.jpg', 'sha1' => 'bbb', 'expected' => false],
    ]]));
});

afterEach(fn () => File::deleteDirectory(base_path($this->dir)));

function storeVerdict(string $dir, string $model, string $hash, bool $isThreat, float $confidence): void
{
    $context = app(ClaudeDetectionVerifier::class)->contextFor(new Camera(['name' => 'Cámara de evaluación', 'fov' => 90]), EvaluateAi::CONTEXT);
    $key = VerdictCache::keyFromImageHash($model, ClaudeDetectionVerifier::promptFingerprint(), $context, $hash);
    File::put(base_path("{$dir}/verdicts/{$key}.json"), json_encode(['verdict' => ['is_threat' => $isThreat, 'confidence' => $confidence, 'description' => 'x', 'model' => $model]]));
}

it('passes offline when the recorded verdicts meet the thresholds', function () {
    storeVerdict($this->dir, 'haiku', 'aaa', true, 0.95);
    storeVerdict($this->dir, 'haiku', 'bbb', false, 0.9);

    $this->artisan('vigilia:eval-gate', ['--dir' => $this->dir])->assertSuccessful();
});

it('uses the review model for ambiguous first-pass verdicts, like production', function () {
    storeVerdict($this->dir, 'haiku', 'aaa', true, 0.95);
    storeVerdict($this->dir, 'haiku', 'bbb', true, 0.5);
    storeVerdict($this->dir, 'sonnet', 'bbb', false, 0.9);

    $this->artisan('vigilia:eval-gate', ['--dir' => $this->dir])->assertSuccessful();
});

it('fails when a false alarm gets through', function () {
    storeVerdict($this->dir, 'haiku', 'aaa', true, 0.95);
    storeVerdict($this->dir, 'haiku', 'bbb', true, 0.9);

    $this->artisan('vigilia:eval-gate', ['--dir' => $this->dir])->assertFailed();
});

it('fails when the model changed without re-recording', function () {
    storeVerdict($this->dir, 'haiku', 'aaa', true, 0.95);
    storeVerdict($this->dir, 'haiku', 'bbb', false, 0.9);
    config(['vigilia.ai.model' => 'another-model']);

    $this->artisan('vigilia:eval-gate', ['--dir' => $this->dir])->assertFailed();
});
