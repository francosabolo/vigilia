<?php

use Anthropic\Client;
use App\Models\Camera;
use App\Services\Evaluation\EvalRuns;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\VerdictCache;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('reuses cached verdicts instead of calling the model again', function () {
    Storage::fake('local');
    Storage::disk('local')->put('frame.jpg', 'jpeg-bytes');
    $verifier = new ClaudeDetectionVerifier(new Client(apiKey: 'invalid-key-never-called'));
    $verifier->cache = app(VerdictCache::class);
    $camera = new Camera(['name' => 'Eval', 'fov' => 90]);

    $prepared = $verifier->prepareSnapshot('frame.jpg', $camera, 'ctx');
    $key = $verifier->cache->key(config('vigilia.ai.model'), ClaudeDetectionVerifier::promptFingerprint(), $prepared['context'], $prepared['image']['data']);
    $verifier->cache->put($key, new VerificationResult(true, 0.92, 'Persona trepando.', model: config('vigilia.ai.model')), ['input' => 1200, 'output' => 80], batch: true);

    $result = $verifier->verifySnapshot('frame.jpg', $camera, 'ctx');

    expect($result->description)->toBe('Persona trepando.')
        ->and($verifier->usage)->toBe([]); // nothing paid
});

it('changes the prompt fingerprint when the prompt changes', function () {
    expect(ClaudeDetectionVerifier::promptFingerprint())->toHaveLength(12)
        ->and(ClaudeDetectionVerifier::promptFingerprint())->toBe(ClaudeDetectionVerifier::promptFingerprint());
});

it('runs a blind console evaluation and reuses already judged images', function () {
    $set = 'storage/framework/testing/blind-'.uniqid();
    foreach (['positivos' => 2, 'negativos' => 2] as $class => $count) {
        File::ensureDirectoryExists(base_path("{$set}/{$class}"));
        for ($i = 0; $i < $count; $i++) {
            $image = imagecreatetruecolor(20, 20);
            imagefill($image, 0, 0, imagecolorallocate($image, $i * 50, $class === 'positivos' ? 200 : 10, 0));
            imagejpeg($image, base_path("{$set}/{$class}/{$i}.jpg"));
        }
    }

    $this->artisan('vigilia:eval-blind', ['path' => $set, '--label' => 'test-blind'])->assertSuccessful();
    $key = json_decode(File::get(storage_path('app/eval-keys/test-blind.json')), true);
    expect(File::exists(storage_path('app/eval-blind/test-blind/key.json')))->toBeFalse(); // key never next to images

    // A perfect judge.
    $verdicts = collect($key['images'])->map(fn ($truth, $img) => ['img' => $img, 'is_threat' => $truth['expected'], 'confidence' => 0.9, 'suspected_guard' => false, 'description' => 'x'])->values();
    File::put(storage_path('app/eval-blind/test-blind/verdicts.json'), $verdicts->toJson());
    $this->artisan('vigilia:eval-import', ['label' => 'test-blind'])->assertSuccessful();

    $run = app(EvalRuns::class)->all()->firstWhere('label', 'test-blind');
    expect($run['metrics']['recall'])->toEqual(1.0)->and($run['metrics']['false_alarm_rate'])->toEqual(0.0);

    // Second time: everything is reused, nothing to judge.
    $this->artisan('vigilia:eval-blind', ['path' => $set, '--label' => 'test-blind-2'])->expectsOutputToContain('0 images ready')->assertSuccessful();

    $this->artisan('vigilia:eval-compare')->expectsOutputToContain('test-blind')->assertSuccessful();

    File::deleteDirectory(base_path($set));
    File::deleteDirectory(storage_path('app/eval-blind/test-blind'));
    File::deleteDirectory(storage_path('app/eval-blind/test-blind-2'));
    File::delete([storage_path('app/eval-keys/test-blind.json'), storage_path('app/eval-keys/test-blind-2.json')]);
    collect(Storage::files('eval-runs'))->filter(fn ($path) => str_contains($path, 'test-blind'))->each(fn ($path) => Storage::delete($path));
});
