<?php

use Anthropic\Client;
use App\Models\DetectionEvent;
use App\Services\Verification\ClaudeDetectionVerifier;

it('escalates without calling the API when there is no snapshot', function () {
    $event = DetectionEvent::factory()->create(['snapshot_path' => null]);

    $result = (new ClaudeDetectionVerifier(new Client(apiKey: 'test-key')))->verify($event);

    expect($result->isThreat)->toBeTrue()
        ->and($result->confidence)->toBeNull()
        ->and($result->description)->toContain('Sin imagen para verificar');
});
