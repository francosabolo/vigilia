<?php

use App\Enums\ZoneKind;
use App\Events\DetectionEventReceived;
use App\Events\IncidentOpened;
use App\Jobs\CheckForFire;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('seguria.media_disk'));
    $this->site = Site::factory()->create();
    $this->edge = EdgeDevice::factory()->create(['site_id' => $this->site->id, 'last_seen_at' => now()]);
    $this->token = $this->edge->issueToken();
});

it('raises a power cut when the edge runs on battery and closes it when power is back', function () {
    Event::fake([IncidentOpened::class]);

    $this->withToken($this->token)->postJson('/api/edge/heartbeat', ['power' => 'battery'])->assertOk();
    $this->artisan('seguria:watch-equipment')->assertSuccessful();
    $incident = Incident::sole();
    expect($incident->title)->toStartWith('Corte de luz');

    $this->withToken($this->token)->postJson('/api/edge/heartbeat', ['power' => 'mains'])->assertOk();
    $this->artisan('seguria:watch-equipment')->assertSuccessful();
    expect($incident->fresh()->status->value)->toBe('resolved');
});

it('does not call a power cut sabotage when every camera drops with the edge on battery', function () {
    Event::fake([IncidentOpened::class]);
    $this->edge->update(['on_battery_since' => now()]);
    Camera::factory()->count(3)->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'is_online' => false, 'last_seen_at' => now()->subMinutes(5)]);

    $this->artisan('seguria:watch-equipment')->assertSuccessful();

    expect(Incident::where('title', 'like', 'Posible sabotaje%')->count())->toBe(0)
        ->and(Incident::where('title', 'like', 'Corte de luz%')->count())->toBe(1);
});

it('records the internet gap and what the edge kept meanwhile', function () {
    Event::fake([IncidentOpened::class]);
    $this->edge->update(['last_seen_at' => now()->subMinutes(30)]);
    $this->artisan('seguria:watch-equipment')->assertSuccessful();

    $this->withToken($this->token)->postJson('/api/edge/heartbeat', ['queued' => 4])->assertOk();
    $this->artisan('seguria:watch-equipment')->assertSuccessful();

    expect($this->edge->fresh()->connectivity_gaps)->toHaveCount(1)
        ->and(Incident::sole()->actions()->latest('id')->value('notes'))->toContain('4 eventos guardados');
});

it('sends motion without a person to the AI only for watched cameras, in their high-risk zones', function () {
    Event::fake([DetectionEventReceived::class]);
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'frigate_name' => 'fondo']);
    $camera->zones()->create(['name' => 'Alambrado', 'frigate_zone' => 'alambrado', 'kind' => ZoneKind::Perimeter, 'watched_labels' => ['person']]);
    $camera->zones()->create(['name' => 'Pileta', 'frigate_zone' => 'pileta', 'kind' => ZoneKind::Pool, 'watched_labels' => ['person']]);
    $motion = ['id' => 'motion-fondo-1', 'camera' => 'fondo', 'label' => 'motion', 'start_time' => now()->timestamp];

    $this->withToken($this->token)->postJson('/api/edge/events', $motion)->assertOk()->assertJsonPath('ignored', 'motion_not_watched');

    $camera->update(['watch_motion' => true]);
    $this->withToken($this->token)->postJson('/api/edge/events', $motion)->assertCreated();

    expect(DetectionEvent::sole()->zones)->toBe(['alambrado']);
    Event::assertDispatched(DetectionEventReceived::class);
    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonPath('motion_cameras', ['fondo']);
});

it('looks for smoke or fire in every fresh frame of the cameras watched for it', function () {
    Queue::fake();
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'frigate_name' => 'sala']);
    $frame = fn () => UploadedFile::fake()->image('f.jpg', 64, 36);

    $this->withToken($this->token)->post('/api/edge/health-frames', ['camera' => 'sala', 'image' => $frame()])->assertOk();
    Queue::assertNotPushed(CheckForFire::class);

    $camera->update(['fire_watch' => true]);
    $this->withToken($this->token)->post('/api/edge/health-frames', ['camera' => 'sala', 'image' => $frame()])->assertOk();
    $this->withToken($this->token)->post('/api/edge/health-frames', ['camera' => 'sala', 'image' => $frame()])->assertOk();
    Queue::assertPushed(CheckForFire::class, 1);
});

it('knows what to watch for in a warehouse loading bay and around forklifts', function () {
    expect(ZoneKind::Loading->defaultLabels())->toContain('truck')
        ->and(ZoneKind::Loading->aiHint())->toContain('fuera del horario de carga')
        ->and(ZoneKind::Hazard->aiHint())->toContain('montacargas');
});

it('sets up the camera siren from the console and sends it with the speaker test only', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'frigate_name' => 'porton']);
    $admin = App\Models\User::factory()->create(['role' => App\Enums\UserRole::Admin, 'organization_id' => $this->site->organization_id]);

    $this->actingAs($admin)->put("/cameras/{$camera->id}/siren", ['brand' => 'hikvision', 'host' => '192.168.1.64', 'user' => 'admin', 'password' => 's3creta'])->assertRedirect();
    expect($camera->fresh()->getRawOriginal('siren'))->not->toContain('s3creta');

    // Saving again without a password keeps the saved one.
    $this->actingAs($admin)->put("/cameras/{$camera->id}/siren", ['brand' => 'hikvision', 'host' => '192.168.1.65', 'user' => 'admin'])->assertRedirect();
    expect($camera->fresh()->siren)->toMatchArray(['host' => '192.168.1.65', 'password' => 's3creta']);

    $this->actingAs($admin)->post("/cameras/{$camera->id}/speaker-test")->assertRedirect();
    $this->withToken($this->token)->getJson('/api/edge/commands')
        ->assertJsonPath('deterrence.0.siren.brand', 'hikvision')
        ->assertJsonPath('deterrence.0.siren.password', 's3creta');
    $this->actingAs($admin)->get("/sites/{$this->site->id}?tab=camaras")->assertDontSee('s3creta');
});
