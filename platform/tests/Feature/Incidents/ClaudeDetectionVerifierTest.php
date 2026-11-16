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
    config(['seguria.media_disk' => 'local', 'seguria.ai.daily_cap_per_site' => 1]);
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
