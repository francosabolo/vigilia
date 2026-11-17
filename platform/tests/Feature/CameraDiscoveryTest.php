<?php

use App\Enums\UserRole;
use App\Models\CameraSetup;
use App\Models\EdgeDevice;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->edge = EdgeDevice::factory()->create(['site_id' => $this->site->id]);
    $this->token = $this->edge->issueToken();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
});

it('asks the edge to scan the network once and shows what it found', function () {
    $this->actingAs($this->admin)->post("/edges/{$this->edge->id}/discover")->assertRedirect();

    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonPath('discover', true);
    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonPath('discover', false);

    $this->withToken($this->token)->postJson('/api/edge/discovered', ['devices' => [['address' => '192.168.1.64', 'name' => 'HIKVISION DS-2CD1043']]])->assertOk();

    $this->actingAs($this->admin)->get('/equipment')->assertInertia(fn ($page) => $page
        ->where('sites.0.devices.0.discovered.0.address', '192.168.1.64')
        ->where('sites.0.devices.0.discovering', false));
});

it('sends a picked camera to the edge once and wipes its credentials when added', function () {
    $this->actingAs($this->admin)->post("/edges/{$this->edge->id}/cameras", [
        'host' => '192.168.1.64', 'name' => 'Portón de entrada', 'brand' => 'hikvision', 'user' => 'admin', 'password' => 'cl@ve',
    ])->assertRedirect();

    $setup = CameraSetup::sole();
    expect($setup->name)->toBe('porton_de_entrada')
        ->and($setup->getRawOriginal('stream_url'))->not->toContain('cl%40ve')->not->toContain('cl@ve')
        ->and($setup->stream_url)->toBe('rtsp://admin:cl%40ve@192.168.1.64:554/Streaming/Channels/101');

    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonPath('add_cameras.0.url', $setup->stream_url);
    $this->withToken($this->token)->getJson('/api/edge/commands')->assertJsonCount(0, 'add_cameras');

    $this->withToken($this->token)->postJson("/api/edge/camera-setups/{$setup->id}", ['ok' => true, 'result' => 'Agregada a Frigate'])->assertOk();
    expect($setup->fresh())->status->toBe('done')->stream_url->toBeNull();
});

it('does not let another edge report on a setup that is not its own', function () {
    $setup = CameraSetup::create(['edge_device_id' => $this->edge->id, 'name' => 'x', 'host' => '10.0.0.2', 'stream_url' => 'rtsp://a:b@10.0.0.2/']);
    $other = EdgeDevice::factory()->create();

    $this->withToken($other->issueToken())->postJson("/api/edge/camera-setups/{$setup->id}", ['ok' => true])->assertNotFound();
});
