<?php

use App\Enums\DetectionStatus;
use App\Models\AccessAuthorization;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Site;
use App\Services\Rules\ArmingEvaluator;
use App\Services\Verification\ClaudeDetectionVerifier;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->site = Site::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
    $this->camera = Camera::factory()->create(['site_id' => $this->site->id, 'is_access' => true]);
    $this->zone = $this->camera->zones()->create(['name' => 'Cochera', 'frigate_zone' => 'cochera', 'kind' => 'garage', 'watched_labels' => ['person']]);
});

it('tells the AI what to watch for in the zone, the access and who is working today', function () {
    AccessAuthorization::create([
        'site_id' => $this->site->id, 'kind' => 'provider', 'name' => 'Pedro Jardinero', 'code' => '111111', 'token' => (string) Str::uuid(),
        'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString(), 'notes' => 'Jardinero',
    ]);
    $event = DetectionEvent::factory()->create(['camera_id' => $this->camera->id, 'label' => 'person', 'zones' => ['cochera'], 'status' => DetectionStatus::Pending]);

    $context = (new ReflectionMethod(ClaudeDetectionVerifier::class, 'eventContext'))->invoke(app(ClaudeDetectionVerifier::class), $event);

    expect($context)->toContain('Es una cochera')->toContain('cámara del acceso')->toContain('Pedro Jardinero');
});

it('arms the rest of the perimeter while an intrusion is open elsewhere', function () {
    $other = Camera::factory()->create(['site_id' => $this->site->id]);
    $perimeter = $other->zones()->create(['name' => 'Alambrado', 'frigate_zone' => 'alambrado', 'kind' => 'perimeter', 'watched_labels' => ['person']]);
    $perimeter->armingSchedules()->create(['days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '03:00', 'ends_at' => '03:01']);
    $evaluator = app(ArmingEvaluator::class);
    $this->travelTo(now()->setTimezone('America/Argentina/Buenos_Aires')->setTime(15, 0)->utc());

    expect($evaluator->isArmed($perimeter->fresh(), now(), 'America/Argentina/Buenos_Aires'))->toBeFalse();

    Incident::factory()->create(['site_id' => $this->site->id, 'camera_id' => $this->camera->id, 'source' => 'detection', 'detected_at' => now()->subMinutes(3)]);

    expect($evaluator->isArmed($perimeter->fresh(), now(), 'America/Argentina/Buenos_Aires'))->toBeTrue();
});
