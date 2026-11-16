<?php

use App\Events\DetectionEventReceived;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Event::fake([DetectionEventReceived::class]);

    $this->device = EdgeDevice::factory()->create();
    $this->token = $this->device->issueToken();
});

function frigatePayload(array $overrides = []): array
{
    return array_merge([
        'id' => '1731790000.123-abc123',
        'camera' => 'perimetro_norte',
        'label' => 'person',
        'score' => 0.87,
        'zones' => ['alambrado'],
        'start_time' => 1731790000.12,
        'end_time' => 1731790012.5,
    ], $overrides);
}

it('rejects requests without a valid edge token', function () {
    $this->postJson('/api/edge/events', frigatePayload())->assertUnauthorized();

    $this->withToken('edge_invalid')->postJson('/api/edge/events', frigatePayload())->assertUnauthorized();

    expect(DetectionEvent::count())->toBe(0);
});

it('stores a detection with its media and auto-registers the camera', function () {
    $response = $this->withToken($this->token)->post('/api/edge/events', frigatePayload([
        'snapshot' => UploadedFile::fake()->image('snapshot.jpg'),
        'clip' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
    ]), ['Accept' => 'application/json']);

    $response->assertCreated()->assertJson(['created' => true]);

    $event = DetectionEvent::sole();
    $camera = Camera::sole();

    expect($camera->frigate_name)->toBe('perimetro_norte')
        ->and($camera->site_id)->toBe($this->device->site_id)
        ->and($event->camera_id)->toBe($camera->id)
        ->and($event->label)->toBe('person')
        ->and($event->zones)->toBe(['alambrado'])
        ->and($event->started_at->timestamp)->toBe(1731790000);

    Storage::disk('local')->assertExists($event->snapshot_path);
    Storage::disk('local')->assertExists($event->clip_path);
    Event::assertDispatched(DetectionEventReceived::class, fn ($e) => $e->detectionEvent->is($event));
});

it('is idempotent when the bridge retries the same event', function () {
    $this->withToken($this->token)->postJson('/api/edge/events', frigatePayload())->assertCreated();
    $this->withToken($this->token)->postJson('/api/edge/events', frigatePayload())
        ->assertOk()
        ->assertJson(['created' => false]);

    expect(DetectionEvent::count())->toBe(1);
    Event::assertDispatchedTimes(DetectionEventReceived::class, 1);
});

it('validates the payload', function () {
    $this->withToken($this->token)
        ->postJson('/api/edge/events', frigatePayload(['label' => null, 'score' => 3]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['label', 'score']);
});

it('records the heartbeat and camera status', function () {
    $this->withToken($this->token)->postJson('/api/edge/heartbeat', [
        'version' => '0.1.0',
        'cameras' => [
            ['name' => 'perimetro_norte', 'online' => true],
            ['name' => 'perimetro_sur', 'online' => false],
        ],
    ])->assertOk();

    $this->device->refresh();

    expect($this->device->version)->toBe('0.1.0')
        ->and($this->device->last_seen_at)->not->toBeNull()
        ->and(Camera::where('frigate_name', 'perimetro_norte')->value('is_online'))->toBeTrue()
        ->and(Camera::where('frigate_name', 'perimetro_sur')->value('is_online'))->toBeFalse();
});
