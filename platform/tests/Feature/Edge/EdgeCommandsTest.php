<?php

use App\Enums\Severity;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\SnapshotRequest;
use App\Models\Zone;
use App\Services\Cameras\CameraWall;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\VerificationResult;
use App\Services\Zones\ZoneSync;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->device = EdgeDevice::factory()->create();
    $this->token = $this->device->issueToken();
    $this->camera = Camera::factory()->create(['site_id' => $this->device->site_id, 'edge_device_id' => $this->device->id, 'frigate_name' => 'perimetro_norte', 'sector' => 'Norte']);
});

it('hands pending live-frame requests to the edge once', function () {
    $request = SnapshotRequest::factory()->create(['edge_device_id' => $this->device->id, 'camera_id' => $this->camera->id]);

    $this->withToken($this->token)->getJson('/api/edge/commands')
        ->assertOk()
        ->assertJsonPath('snapshots.0.id', $request->id)
        ->assertJsonPath('snapshots.0.camera', 'perimetro_norte')
        ->assertJsonPath('preview', null);

    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonCount(0, 'snapshots');
});

it('asks for previews only while someone watches the camera wall', function () {
    app(CameraWall::class)->markWatched($this->device->site_id);

    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonPath('preview.cameras', ['perimetro_norte']);

    $this->withToken($this->token)->post('/api/edge/previews', [
        'camera' => 'perimetro_norte',
        'image' => UploadedFile::fake()->image('p.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('local')->assertExists("previews/{$this->camera->id}.jpg");
});

it('lets the AI review a live frame and raises the incident severity', function () {
    app()->instance(DetectionVerifier::class, new class implements DetectionVerifier
    {
        public function verify(DetectionEvent $event): VerificationResult
        {
            throw new LogicException('not used');
        }

        public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
        {
            return new VerificationResult(true, 0.9, 'Una persona agachada junto al alambrado.');
        }
    });
    $incident = Incident::factory()->create(['site_id' => $this->device->site_id, 'camera_id' => null, 'severity' => Severity::Medium, 'source' => 'resident_report']);
    $request = SnapshotRequest::factory()->create(['edge_device_id' => $this->device->id, 'camera_id' => $this->camera->id, 'incident_id' => $incident->id]);

    $this->withToken($this->token)->post("/api/edge/snapshots/{$request->id}", ['image' => UploadedFile::fake()->image('live.jpg')], ['Accept' => 'application/json'])->assertOk();

    $incident->refresh();
    expect($request->fresh()->status)->toBe('fulfilled')
        ->and($incident->severity)->toBe(Severity::High)
        ->and($incident->camera_id)->toBe($this->camera->id)
        ->and($incident->actions()->where('type', 'ai_review')->value('notes'))->toContain('agachada');
});

it('rejects frames for requests of another edge', function () {
    $request = SnapshotRequest::factory()->create();

    $this->withToken($this->token)->post("/api/edge/snapshots/{$request->id}", ['image' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertNotFound();
});

it('syncs drawn zones to the edge and tracks the applied version', function () {
    Zone::factory()->for($this->camera)->create(['frigate_zone' => 'alambrado', 'watched_labels' => ['person'], 'coordinates' => [[0.1, 0.5], [0.9, 0.5], [0.9, 1]]]);
    Zone::factory()->for(Camera::factory()->create(['site_id' => $this->device->site_id, 'edge_device_id' => $this->device->id, 'frigate_name' => 'sin_dibujo']))->create(['coordinates' => null]);

    $zones = $this->withToken($this->token)->getJson('/api/edge/commands')->json('zones');

    expect($zones['cameras'])->toBe(['perimetro_norte' => ['alambrado' => ['coordinates' => '0.1,0.5,0.9,0.5,0.9,1', 'objects' => ['person']]]]);

    $this->withToken($this->token)->postJson('/api/edge/heartbeat', ['zones_version' => $zones['version']])->assertOk();
    expect(app(ZoneSync::class)->isSynced($this->device->fresh()))->toBeTrue();
});
