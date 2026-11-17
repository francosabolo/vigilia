<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->organization->id]);
});

it('lets the admin see the app as a resident and come back', function () {
    $resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => 'Marta']);

    $this->actingAs($this->admin)->post("/team/{$resident->id}/impersonate")->assertRedirect('/vecinos');
    $this->assertAuthenticatedAs($resident);
    $this->get('/vecinos')->assertOk()->assertInertia(fn ($page) => $page->where('impersonating.name', 'Marta'));

    $this->post('/impersonation/leave')->assertRedirect('/team');
    $this->assertAuthenticatedAs($this->admin);
});

it('skips the second factor of the person being viewed, since the admin passed theirs', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->organization->id]);

    $this->actingAs($this->admin)->post("/team/{$operator->id}/impersonate");
    config(['seguria.require_two_factor' => true]);
    $this->get('/dashboard')->assertOk();
});

it('only lets admins view as people of their own company, never as another admin', function () {
    $otherAdmin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->organization->id]);
    $outsider = User::factory()->create(['role' => UserRole::Guard]);
    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->organization->id, 'site_id' => $this->site->id]);

    $this->actingAs($this->admin)->post("/team/{$otherAdmin->id}/impersonate")->assertForbidden();
    $this->actingAs($this->admin)->post("/team/{$outsider->id}/impersonate")->assertNotFound();
    $this->actingAs($guard)->post("/team/{$this->admin->id}/impersonate")->assertForbidden();
});
