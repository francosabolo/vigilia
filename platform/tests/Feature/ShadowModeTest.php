<?php

use App\Enums\IncidentResolution;
use App\Enums\UserRole;
use App\Jobs\RunShadowVerification;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\ShadowVerdict;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentWorkflow;
use App\Services\Shadow\ShadowMode;
use App\Services\Shadow\ShadowReport;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\Queue;

function shadowEvent(array $attributes = []): DetectionEvent
{
    return DetectionEvent::factory()->create($attributes + ['snapshot_path' => 'sites/1/a.jpg', 'ai_verdict' => ['is_threat' => false, 'description' => 'Un perro']]);
}

function fakeChallenger(bool $isThreat, float $confidence = 0.9): void
{
    app()->instance(ClaudeDetectionVerifier::class, Mockery::mock(ClaudeDetectionVerifier::class, function ($mock) use ($isThreat, $confidence) {
        $mock->shouldReceive('challenge')->andReturn([
            'result' => new VerificationResult(isThreat: $isThreat, confidence: $confidence, description: 'retador', model: 'claude-sonnet-5-5'),
            'input_tokens' => 1500,
            'output_tokens' => 100,
            'latency_ms' => 1200,
        ]);
    }));
}

it('queues the challenger only when enabled, for real sites and within the sample', function () {
    Queue::fake();
    $event = shadowEvent();

    app(ShadowMode::class)->consider($event);
    Queue::assertNothingPushed();

    config(['vigilia.ai.shadow.enabled' => true, 'vigilia.ai.shadow.sample_rate' => 1.0]);
    app(ShadowMode::class)->consider($event);
    Queue::assertPushed(RunShadowVerification::class);

    Queue::fake();
    $event->camera->site->update(['is_simulated' => true]);
    app(ShadowMode::class)->consider($event->fresh('camera.site'));
    Queue::assertNothingPushed();
});

it('stores what the challenger would have said, with its cost, and never twice', function () {
    fakeChallenger(isThreat: true);
    $event = shadowEvent();

    $verdict = app(ShadowMode::class)->run($event);

    expect($verdict->alerted)->toBeTrue()
        ->and($verdict->champion_alerted)->toBeFalse()
        ->and($verdict->cost_usd)->toBeGreaterThan(0)
        ->and(app(ShadowMode::class)->run($event))->toBeNull()
        ->and(ShadowVerdict::count())->toBe(1);
});

it('uses closed incidents as ground truth for their detections', function () {
    $incident = Incident::factory()->create();
    $event = shadowEvent(['incident_id' => $incident->id]);

    app(IncidentWorkflow::class)->resolve($incident, IncidentResolution::FalseAlarm, null, 'web');

    expect($event->fresh()->ground_truth)->toBeFalse()->and($event->fresh()->ground_truth_source)->toBe('incident');
});

it('compares both sides on labeled detections and recommends', function () {
    $site = Site::factory()->create();
    $camera = Camera::factory()->create(['site_id' => $site->id]);
    foreach (range(1, 40) as $i) {
        $threat = $i <= 10;
        $event = shadowEvent(['camera_id' => $camera->id, 'ground_truth' => $threat, 'ground_truth_source' => 'simulation']);
        ShadowVerdict::create([
            'detection_event_id' => $event->id, 'challenger' => 'retador', 'model' => 'm', 'prompt' => 'abc',
            // Production misses one intrusion and lets 3 false alarms through; the challenger gets all right.
            'champion_alerted' => $threat ? $i !== 1 : $i <= 13,
            'alerted' => $threat, 'is_threat' => $threat, 'cost_usd' => 0.001, 'latency_ms' => 900,
        ]);
    }

    $report = app(ShadowReport::class)->for('retador', [$site->id]);

    expect($report['labeled'])->toBe(40)
        ->and($report['champion']['recall'])->toBe(0.9)
        ->and($report['candidate']['recall'])->toBe(1.0)
        ->and($report['candidate']['false_alarm_rate'])->toBe(0.0)
        ->and($report['recommendation']['status'])->toBe('promote')
        ->and($report['disagreements'])->toHaveCount(4);
});

it('lets the central label a detection and shows the page only to the central', function () {
    $event = shadowEvent();
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $event->camera->site->organization_id]);
    $guard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => $event->camera->site_id]);

    $this->actingAs($guard)->get('/ai/shadow')->assertForbidden();
    $this->actingAs($operator)->get('/ai/shadow')->assertOk();

    $this->actingAs($operator)->post("/ai/shadow/detections/{$event->id}/label", ['threat' => true])->assertRedirect();
    expect($event->fresh()->ground_truth)->toBeTrue()->and($event->fresh()->reviewed_by)->toBe($operator->id);
});
