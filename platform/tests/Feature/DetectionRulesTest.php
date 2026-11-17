<?php

use App\Enums\DetectionStatus;
use App\Enums\Severity;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\DetectionProcessor;
use App\Services\Verification\ClaudeDetectionVerifier;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\VerificationResult;

function verifierSaying(bool $threat, string $description): void
{
    app()->instance(DetectionVerifier::class, new class($threat, $description) implements DetectionVerifier
    {
        public function __construct(private bool $threat, private string $description) {}

        public function verify(DetectionEvent $event): VerificationResult
        {
            return new VerificationResult(isThreat: $this->threat, confidence: 0.9, description: $this->description, model: 'fake');
        }

        public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
        {
            return new VerificationResult(isThreat: $this->threat, confidence: 0.9, description: $this->description, model: 'fake');
        }
    });
}

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->edge = EdgeDevice::factory()->create(['site_id' => $this->site->id]);
    $this->camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'name' => 'Perímetro sur']);
});

it('makes climbing the wall a critical alert', function () {
    verifierSaying(true, 'Una persona trepando el muro sur con una mochila.');
    $event = DetectionEvent::factory()->create(['camera_id' => $this->camera->id, 'edge_device_id' => $this->edge->id, 'label' => 'person', 'zones' => [], 'status' => DetectionStatus::Pending]);

    app(DetectionProcessor::class)->process($event);

    expect(Incident::sole()->severity)->toBe(Severity::Critical)
        ->and(DetectionProcessor::isRiskyAction('Dos personas cortando el alambrado'))->toBeTrue()
        ->and(DetectionProcessor::isRiskyAction('Una persona caminando por la vereda'))->toBeFalse();
});

it('alerts a vehicle stopped by the perimeter for a long time even when nobody is in sight', function () {
    verifierSaying(false, 'Auto gris estacionado con las luces apagadas, sin personas visibles.');
    $event = DetectionEvent::factory()->create([
        'camera_id' => $this->camera->id, 'edge_device_id' => $this->edge->id, 'label' => 'car', 'zones' => [],
        'status' => DetectionStatus::Pending, 'started_at' => now()->subMinutes(12), 'ended_at' => now(),
    ]);

    app(DetectionProcessor::class)->process($event);

    expect(Incident::sole()->title)->toBe('Auto detenido 12 min en Perímetro sur');

    $short = DetectionEvent::factory()->create([
        'camera_id' => $this->camera->id, 'edge_device_id' => $this->edge->id, 'label' => 'car', 'zones' => [],
        'status' => DetectionStatus::Pending, 'started_at' => now()->subMinute(), 'ended_at' => now(),
    ]);
    app(DetectionProcessor::class)->process($short);
    expect($short->fresh()->status)->toBe(DetectionStatus::Dismissed);
});

it('teaches the AI about the camera when a guard closes a false alarm saying what it was', function () {
    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $incident = Incident::factory()->create(['site_id' => $this->site->id, 'camera_id' => $this->camera->id, 'source' => 'detection']);

    $this->actingAs($guard)->post("/incidents/{$incident->id}/resolve", ['resolution' => 'false_alarm', 'lesson' => 'El perro del lote 12'])->assertRedirect();

    expect($this->camera->fresh()->ai_notes)->toBe('- El perro del lote 12')
        ->and(app(ClaudeDetectionVerifier::class)->contextFor($this->camera->fresh(), 'Detector: persona.'))->toContain('El perro del lote 12');
});
