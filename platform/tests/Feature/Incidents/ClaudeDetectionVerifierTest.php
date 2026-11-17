<?php

use Anthropic\Client;
use App\Models\DetectionEvent;
use App\Services\Verification\AiBudget;
use App\Services\Verification\ClaudeDetectionVerifier;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

it('escalates without calling the API when there is no snapshot', function () {
    $event = DetectionEvent::factory()->create(['snapshot_path' => null]);

    $result = (new ClaudeDetectionVerifier(new Client(apiKey: 'test-key')))->verify($event);

    expect($result->isThreat)->toBeTrue()
        ->and($result->confidence)->toBeNull()
        ->and($result->description)->toContain('Sin imagen para verificar');
});

it('alerts without calling the API once the site used its daily budget', function () {
    Notification::fake();
    Storage::fake('local');
    config(['vigilia.media_disk' => 'local', 'vigilia.ai.daily_cap_per_site' => 1]);
    $image = imagecreatetruecolor(64, 36);
    ob_start();
    imagejpeg($image);
    Storage::disk('local')->put('snap.jpg', ob_get_clean());
    $event = DetectionEvent::factory()->create(['snapshot_path' => 'snap.jpg']);
    app(AiBudget::class)->consume($event->camera->site_id);

    // A client with a fake key would fail if it were called: the budget check must come first.
    $result = (new ClaudeDetectionVerifier(new Client(apiKey: 'test-key')))->verify($event);

    expect($result->isThreat)->toBeTrue()
        ->and($result->confidence)->toBeNull()
        ->and($result->description)->toContain('Tope diario');
});

it('sends every frame in order and a separate prompt for sequences', function () {
    $images = [['data' => 'AAA', 'media_type' => 'image/jpeg'], ['data' => 'BBB', 'media_type' => 'image/jpeg'], ['data' => 'CCC', 'media_type' => 'image/jpeg']];
    $verifier = new ClaudeDetectionVerifier(new Client(apiKey: 'test-key'));

    $sequence = $verifier->requestParams('m', 'contexto', $images, ClaudeDetectionVerifier::sequencePrompt());
    $single = $verifier->requestParams('m', 'contexto', $images[0]);

    expect(collect($sequence['messages'][0]['content'])->where('type', 'image')->pluck('source.data')->all())->toBe(['AAA', 'BBB', 'CCC'])
        ->and($sequence['system'])->toContain('varios cuadros del mismo clip')
        ->and($single['system'])->toBe(ClaudeDetectionVerifier::SYSTEM_PROMPT)
        ->and(ClaudeDetectionVerifier::promptFingerprint(ClaudeDetectionVerifier::sequencePrompt()))->not->toBe(ClaudeDetectionVerifier::promptFingerprint());
});
