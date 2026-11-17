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
use App\Notifications\NoisyCameraNotice;
use App\Services\Incidents\DetectionProcessor;
use App\Services\Incidents\EquipmentWatch;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->edge = EdgeDevice::factory()->create(['site_id' => $this->site->id, 'last_seen_at' => now()]);
});

it('raises several cameras going dark together as possible sabotage', function () {
    $cameras = Camera::factory()->count(3)->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'is_online' => false, 'last_seen_at' => now()->subMinutes(4)]);

    app(EquipmentWatch::class)->run();

    $sabotage = Incident::where('title', 'like', 'Posible sabotaje%')->sole();
    expect($sabotage->severity)->toBe(Severity::Critical)->and($sabotage->title)->toContain('3 cámaras');
    app(EquipmentWatch::class)->run();
    expect(Incident::where('title', 'like', 'Posible sabotaje%')->count())->toBe(1);
});

it('groups detections of a noisy camera with the one the AI just dismissed, and warns once', function () {
    Notification::fake();
    $calls = 0;
    app()->instance(DetectionVerifier::class, new class($calls) implements DetectionVerifier
    {
        public function __construct(public int &$calls) {}

        public function verify(DetectionEvent $event): VerificationResult
        {
            $this->calls++;

            return new VerificationResult(isThreat: false, confidence: 0.95, description: 'Gotas de lluvia frente a la lente.', model: 'fake');
        }

        public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
        {
            return $this->verify(new DetectionEvent);
        }
    });
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id]);
    DetectionEvent::factory()->count(70)->create(['camera_id' => $camera->id, 'label' => 'person', 'status' => DetectionStatus::Dismissed, 'started_at' => now()->subMinutes(30), 'ai_verdict' => ['model' => 'fake']]);
    User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);

    $first = DetectionEvent::factory()->create(['camera_id' => $camera->id, 'edge_device_id' => $this->edge->id, 'label' => 'person', 'zones' => [], 'status' => DetectionStatus::Pending, 'started_at' => now()]);
    app(DetectionProcessor::class)->process($first);
    $second = DetectionEvent::factory()->create(['camera_id' => $camera->id, 'edge_device_id' => $this->edge->id, 'label' => 'person', 'zones' => [], 'status' => DetectionStatus::Pending, 'started_at' => now()->addSeconds(10)]);
    app(DetectionProcessor::class)->process($second);

    expect($second->fresh()->ai_verdict['grouped_with'] ?? null)->not->toBeNull()
        ->and($second->fresh()->status)->toBe(DetectionStatus::Dismissed);
    Notification::assertSentTimes(NoisyCameraNotice::class, 1);
});
