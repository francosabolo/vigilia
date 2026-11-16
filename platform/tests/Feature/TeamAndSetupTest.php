<?php

use App\Enums\UserRole;
use App\Models\EdgeDevice;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->organization->id]);
});

it('lets an admin invite a guard for a site, who gets the set-password e-mail', function () {
    Notification::fake();

    $this->actingAs($this->admin)->post('/team', [
        'name' => 'Ana Guardia', 'email' => 'ana@example.com', 'role' => 'guard', 'site_id' => $this->site->id,
    ])->assertSessionHasNoErrors();

    $guard = User::where('email', 'ana@example.com')->sole();
    expect($guard->role)->toBe(UserRole::Guard)->and($guard->site_id)->toBe($this->site->id)->and($guard->organization_id)->toBe($this->organization->id);
    Notification::assertSentTo($guard, ResetPassword::class);
});

it('requires a site for guards and drops it for the central', function () {
    $this->actingAs($this->admin)->post('/team', ['name' => 'Sin lugar', 'email' => 'x@example.com', 'role' => 'guard'])
        ->assertSessionHasErrors('site_id');

    Notification::fake();
    $this->actingAs($this->admin)->post('/team', ['name' => 'Op', 'email' => 'op@example.com', 'role' => 'operator', 'site_id' => $this->site->id]);
    expect(User::where('email', 'op@example.com')->sole()->site_id)->toBeNull();
});

it('keeps the team admin-only and inside the organization', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->organization->id]);
    $outsider = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($operator)->get('/team')->assertForbidden();
    $this->actingAs($this->admin)->get('/team')->assertOk();
    $this->actingAs($this->admin)->delete("/team/{$outsider->id}")->assertNotFound();
    $this->actingAs($this->admin)->delete("/team/{$this->admin->id}")->assertStatus(422);
    $this->actingAs($this->admin)->patch("/team/{$this->admin->id}", ['name' => 'Yo', 'role' => 'operator'])->assertSessionHasErrors('role');
});

it('creates a building and sends the admin to draw its perimeter', function () {
    $this->actingAs($this->admin)->post('/sites', ['name' => 'Edificio Nuevo', 'kind' => 'building', 'address' => 'Av. Siempreviva 742'])
        ->assertRedirect();

    $site = Site::where('name', 'Edificio Nuevo')->sole();
    expect($site->organization_id)->toBe($this->organization->id);

    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->organization->id]);
    $this->actingAs($operator)->post('/sites', ['name' => 'X', 'kind' => 'country', 'address' => 'Y'])->assertForbidden();
});

it('registers an edge box showing the token once and only storing its hash', function () {
    $response = $this->actingAs($this->admin)->post("/sites/{$this->site->id}/edges", ['name' => 'Garita']);

    $token = session('new_edge')['token'];
    $edge = EdgeDevice::where('site_id', $this->site->id)->sole();
    expect($token)->toStartWith('edge_')
        ->and($edge->token_hash)->toBe(hash('sha256', $token))
        ->and(EdgeDevice::findByToken($token)?->id)->toBe($edge->id);
    $response->assertRedirect();

    $siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin, 'organization_id' => $this->organization->id, 'site_id' => $this->site->id]);
    $this->actingAs($siteAdmin)->post("/sites/{$this->site->id}/edges", ['name' => 'Otro'])->assertForbidden();

    $this->actingAs($this->admin)->delete("/sites/{$this->site->id}/edges/{$edge->id}")->assertRedirect();
    expect(EdgeDevice::count())->toBe(0);
});
