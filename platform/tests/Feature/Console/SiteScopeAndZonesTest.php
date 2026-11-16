<?php

use App\Enums\UserRole;
use App\Enums\ZoneKind;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Services\Zones\ZoneSync;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->alamos = Site::factory()->create(['name' => 'Los Álamos']);
    $this->remanso = Site::factory()->create(['name' => 'El Remanso', 'organization_id' => $this->alamos->organization_id]);
    $this->operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->alamos->organization_id]);
    Incident::factory()->create(['site_id' => $this->alamos->id]);
    Incident::factory()->create(['site_id' => $this->remanso->id]);
});

it('scopes the console to the site picked in the header', function () {
    $this->actingAs($this->operator)->get('/incidents')->assertInertia(fn (Assert $page) => $page
        ->has('incidents', 2)
        ->where('siteContext.current', null)
        ->has('siteContext.sites', 2));

    $this->actingAs($this->operator)->put('/current-site', ['site_id' => $this->remanso->id])->assertRedirect();

    $this->actingAs($this->operator)->get('/incidents')->assertInertia(fn (Assert $page) => $page
        ->has('incidents', 1)
        ->where('siteContext.current.name', 'El Remanso'));
});

it('does not let anyone pick a site outside their access', function () {
    $foreign = Site::factory()->create();

    $this->actingAs($this->operator)->put('/current-site', ['site_id' => $foreign->id])->assertForbidden();
});

it('saves zones drawn on the camera image', function () {
    $camera = Camera::factory()->create(['site_id' => $this->alamos->id]);

    $this->actingAs($this->operator)->post("/cameras/{$camera->id}/zones", [
        'name' => 'Alambrado norte',
        'coordinates' => [[0.1, 0.5], [0.9, 0.5], [0.9, 0.95]],
        'watched_labels' => ['person'],
    ])->assertRedirect();

    $zone = $camera->zones()->sole();
    expect($zone->frigate_zone)->toBe('alambrado_norte')
        ->and($zone->frigateCoordinates())->toBe('0.1,0.5,0.9,0.5,0.9,0.95');

    $this->actingAs($this->operator)->post("/cameras/{$camera->id}/zones", [
        'name' => 'Mala', 'coordinates' => [[0.1, 0.5], [2, 0.5], [0.9, 0.95]],
    ])->assertSessionHasErrors('coordinates.1.0');
});

it('saves the kind of zone and syncs people-only detection for access points', function () {
    $camera = Camera::factory()->create(['site_id' => $this->alamos->id, 'edge_device_id' => EdgeDevice::factory()->create(['site_id' => $this->alamos->id])->id]);

    $this->actingAs($this->operator)->post("/cameras/{$camera->id}/zones", [
        'name' => 'Ventanal del SUM',
        'kind' => 'window',
        'coordinates' => [[0.2, 0.2], [0.4, 0.2], [0.4, 0.5]],
    ])->assertRedirect();

    $zone = $camera->zones()->sole();
    expect($zone->kind)->toBe(ZoneKind::Window);

    $payload = app(ZoneSync::class)->payloadFor($camera->edgeDevice);
    expect($payload['cameras'][$camera->frigate_name]['ventanal_del_sum']['objects'])->toBe(['person']);

    $this->actingAs($this->operator)->post("/cameras/{$camera->id}/zones", ['name' => 'X', 'kind' => 'roof', 'coordinates' => [[0.1, 0.1], [0.2, 0.1], [0.2, 0.2]]])
        ->assertSessionHasErrors('kind');
});
