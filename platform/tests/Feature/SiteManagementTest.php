<?php

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\Client;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('seguria.media_disk'));
    $this->site = Site::factory()->create(['name' => 'Edificio Viejo']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
});

it('lets the managers edit the site data and only the admin change its client', function () {
    $client = Client::factory()->create(['organization_id' => $this->site->organization_id]);
    $siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);

    $this->actingAs($siteAdmin)->patch("/sites/{$this->site->id}/details", ['name' => 'Edificio Nuevo', 'address' => 'Calle 2', 'kind' => 'building', 'client_id' => $client->id])->assertSessionHasNoErrors();
    expect($this->site->fresh())->name->toBe('Edificio Nuevo')->client_id->toBeNull();

    $this->actingAs($this->admin)->patch("/sites/{$this->site->id}/details", ['name' => 'Country Nuevo', 'address' => 'Ruta 8', 'kind' => 'country', 'client_id' => $client->id])->assertSessionHasNoErrors();
    expect($this->site->fresh())->kind->value->toBe('country')->client_id->toBe($client->id);
});

it('archives a site: images and resident accounts go, the incident history stays', function () {
    $edge = EdgeDevice::factory()->create(['site_id' => $this->site->id]);
    $token = $edge->issueToken();
    Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $edge->id]);
    $incident = Incident::factory()->create(['site_id' => $this->site->id]);
    $resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    Storage::disk(config('seguria.media_disk'))->put("sites/{$this->site->id}/events/1/snapshot.jpg", 'x');

    $this->actingAs($this->admin)->delete("/sites/{$this->site->id}", ['confirm' => 'otro nombre'])->assertSessionHasErrors('confirm');
    $this->actingAs($this->admin)->delete("/sites/{$this->site->id}", ['confirm' => 'Edificio Viejo'])->assertRedirect('/sites');

    expect(Site::find($this->site->id))->toBeNull()
        ->and(Site::withTrashed()->find($this->site->id))->not->toBeNull()
        ->and(User::find($resident->id))->toBeNull()
        ->and($guard->fresh()->site_id)->toBeNull()
        ->and(Incident::find($incident->id))->not->toBeNull();
    Storage::disk(config('seguria.media_disk'))->assertMissing("sites/{$this->site->id}/events/1/snapshot.jpg");
    $this->withToken($token)->postJson('/api/edge/heartbeat', [])->assertUnauthorized();
    $this->actingAs($this->admin)->get("/incidents/{$incident->id}")->assertForbidden();
});

it('only lets the central admin archive a site', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->site->organization_id]);

    $this->actingAs($operator)->delete("/sites/{$this->site->id}", ['confirm' => 'Edificio Viejo'])->assertForbidden();
});
