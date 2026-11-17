<?php

use App\Enums\UserRole;
use App\Events\IncidentChanged;
use App\Models\Client;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->organization->id]);
    $this->client = Client::factory()->create(['organization_id' => $this->organization->id]);
    $this->building = Site::factory()->create(['organization_id' => $this->organization->id, 'client_id' => $this->client->id]);
    $this->country = Site::factory()->create(['organization_id' => $this->organization->id, 'client_id' => $this->client->id]);
    $this->otherSite = Site::factory()->create(['organization_id' => $this->organization->id]);
});

it('lets the central admin create a client, assign a site and invite its administration', function () {
    Notification::fake();

    $this->actingAs($this->admin)->post('/clients', ['name' => 'Administración Pérez', 'contact_phone' => '+5491155551234'])->assertSessionHasNoErrors();
    $client = Client::where('name', 'Administración Pérez')->sole();
    expect($client->organization_id)->toBe($this->organization->id);

    $this->actingAs($this->admin)->patch("/sites/{$this->otherSite->id}/client", ['client_id' => $client->id])->assertSessionHasNoErrors();
    expect($this->otherSite->fresh()->client_id)->toBe($client->id);

    $this->actingAs($this->admin)->post("/clients/{$client->id}/admins", ['name' => 'Laura', 'email' => 'laura@example.com'])->assertSessionHasNoErrors();
    $user = User::where('email', 'laura@example.com')->sole();
    expect($user->role)->toBe(UserRole::ClientAdmin)->and($user->client_id)->toBe($client->id);
    Notification::assertSentTo($user, ResetPassword::class);

    $this->actingAs($this->admin)->get('/clients')->assertOk()
        ->assertInertia(fn ($page) => $page->component('clients/index')->has('clients', 2));
});

it("shows the client's administration every site of its client and nothing else", function () {
    $manager = User::factory()->create(['role' => UserRole::ClientAdmin, 'organization_id' => $this->organization->id, 'client_id' => $this->client->id]);
    $mine = Incident::factory()->create(['site_id' => $this->country->id]);
    $notMine = Incident::factory()->create(['site_id' => $this->otherSite->id]);

    expect($manager->accessibleSiteIds())->toEqualCanonicalizing([$this->building->id, $this->country->id])
        ->and($manager->canSwitchSites())->toBeTrue()
        ->and($manager->liveChannel())->toBe("clients.{$this->client->id}");

    $this->actingAs($manager)->get("/incidents/{$mine->id}")->assertOk();
    $this->actingAs($manager)->get("/incidents/{$notMine->id}")->assertForbidden();
    $this->actingAs($manager)->get("/sites/{$this->otherSite->id}")->assertForbidden();
    $this->actingAs($manager)->get("/sites/{$this->building->id}?tab=ajustes")->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', true)->where('canManageEdges', false));
    $this->actingAs($manager)->put('/current-site', ['site_id' => $this->country->id])->assertRedirect();
    $this->actingAs($manager)->put('/current-site', ['site_id' => $this->otherSite->id])->assertForbidden();
    $this->actingAs($manager)->get('/clients')->assertForbidden();
});

it("broadcasts incidents to the client's channel", function () {
    $incident = Incident::factory()->create(['site_id' => $this->building->id]);
    $loose = Incident::factory()->create(['site_id' => $this->otherSite->id]);

    $channels = fn (Incident $incident) => collect((new IncidentChanged($incident, 'opened'))->broadcastOn())->map->name->all();

    expect($channels($incident))->toContain("private-clients.{$this->client->id}")
        ->and($channels($loose))->not->toContain("private-clients.{$this->client->id}");
});

it('keeps the sites and removes the accounts when a client is deleted', function () {
    $manager = User::factory()->create(['role' => UserRole::ClientAdmin, 'organization_id' => $this->organization->id, 'client_id' => $this->client->id]);

    $this->actingAs($this->admin)->delete("/clients/{$this->client->id}")->assertRedirect();

    expect(Client::count())->toBe(0)
        ->and($this->building->fresh()->client_id)->toBeNull()
        ->and(User::find($manager->id))->toBeNull();
});

it('never mixes clients between security companies', function () {
    $foreign = Client::factory()->create();
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->organization->id]);

    $this->actingAs($operator)->get('/clients')->assertForbidden();
    $this->actingAs($this->admin)->patch("/clients/{$foreign->id}", ['name' => 'X'])->assertNotFound();
    $this->actingAs($this->admin)->patch("/sites/{$this->otherSite->id}/client", ['client_id' => $foreign->id])->assertSessionHasErrors('client_id');
    $this->actingAs($this->admin)->post('/sites', ['name' => 'Nuevo', 'kind' => 'building', 'address' => 'Calle 1', 'client_id' => $foreign->id])
        ->assertSessionHasErrors('client_id');
});
