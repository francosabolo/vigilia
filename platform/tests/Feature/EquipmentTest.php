<?php

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('vigilia.media_disk'));
    $this->site = Site::factory()->create();
    $this->edge = EdgeDevice::factory()->create(['site_id' => $this->site->id, 'version' => '0.5.0']);
    $this->token = $this->edge->issueToken();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
});

it('keeps the inventory of every camera and edge box', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id]);

    $this->actingAs($this->admin)->patch("/equipment/camera/{$camera->id}", ['model' => 'Hikvision DS-2CD1043', 'serial' => 'K123', 'warranty_until' => now()->addYear()->toDateString()])->assertRedirect();

    expect($camera->fresh()->model)->toBe('Hikvision DS-2CD1043');
    $this->actingAs($this->admin)->get('/equipment')->assertOk()->assertInertia(fn ($page) => $page->where('sites.0.devices.1.serial', 'K123'));
});

it('asks an old edge box to update itself and stops asking once it reports the new version', function () {
    $this->actingAs($this->admin)->post("/edges/{$this->edge->id}/update")->assertRedirect();
    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonPath('update', true);

    $this->withToken($this->token)->postJson('/api/edge/heartbeat', ['version' => config('vigilia.edge.latest_version')])->assertOk();

    expect($this->edge->fresh()->update_requested_at)->toBeNull();
    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonPath('update', false);
});

it('replaces a broken camera with the new one keeping zones and history', function () {
    $old = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'frigate_name' => 'porton_viejo', 'name' => 'Portón', 'is_online' => false]);
    $old->zones()->create(['name' => 'Portón', 'frigate_zone' => 'porton', 'kind' => 'door', 'watched_labels' => ['person']]);
    $new = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'frigate_name' => 'porton_nuevo']);
    DetectionEvent::factory()->create(['camera_id' => $new->id]);

    $this->actingAs($this->admin)->post("/cameras/{$old->id}/replace", ['new_camera_id' => $new->id])->assertRedirect();

    expect($old->fresh())->frigate_name->toBe('porton_nuevo')->is_online->toBeTrue()
        ->and($old->zones()->count())->toBe(1)
        ->and(Camera::withTrashed()->find($new->id))->toBeNull()
        ->and(DetectionEvent::where('camera_id', $old->id)->count())->toBe(1);
});

it('lets the company set its own brand', function () {
    $this->actingAs($this->admin)->patch('/business/settings', ['brand_name' => 'Seguridad del Norte', 'brand_color' => '#0055aa'])->assertRedirect();

    $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('brand.name', 'Seguridad del Norte')->where('brand.color', '#0055aa'));
});
