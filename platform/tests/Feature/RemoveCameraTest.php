<?php

use App\Enums\UserRole;
use App\Events\DetectionEventReceived;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([DetectionEventReceived::class]);
    $this->device = EdgeDevice::factory()->create();
    $this->token = $this->device->issueToken();
    $this->camera = Camera::factory()->create(['site_id' => $this->device->site_id, 'edge_device_id' => $this->device->id, 'frigate_name' => 'cochera']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->device->site->organization_id]);
});

it('removes a camera from the console but keeps its incidents as evidence', function () {
    $incident = Incident::factory()->create(['site_id' => $this->device->site_id, 'camera_id' => $this->camera->id]);
    $this->camera->zones()->create(['name' => 'Portón', 'frigate_zone' => 'porton', 'kind' => 'door', 'watched_labels' => ['person']]);

    $this->actingAs($this->admin)->delete("/cameras/{$this->camera->id}")->assertRedirect();

    expect(Camera::find($this->camera->id))->toBeNull()
        ->and($this->camera->zones()->count())->toBe(0)
        ->and($incident->fresh()->camera->name)->toBe($this->camera->name);
    $this->actingAs($this->admin)->get("/incidents/{$incident->id}")->assertOk();
});

it('does not bring a removed camera back when the edge still reports it', function () {
    $this->camera->delete();

    $this->withToken($this->token)->postJson('/api/edge/heartbeat', ['cameras' => [['name' => 'cochera', 'online' => true]]])->assertOk();
    $this->withToken($this->token)->postJson('/api/edge/events', [
        'id' => 'x-1', 'camera' => 'cochera', 'label' => 'person', 'start_time' => now()->timestamp,
    ])->assertOk()->assertJson(['ignored' => 'camera_removed']);

    expect(Camera::count())->toBe(0)->and(DetectionEvent::count())->toBe(0);
});

it('only lets the site managers remove cameras', function () {
    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->device->site->organization_id, 'site_id' => $this->device->site_id]);

    $this->actingAs($guard)->delete("/cameras/{$this->camera->id}")->assertForbidden();
});
