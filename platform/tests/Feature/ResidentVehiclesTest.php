<?php

use App\Enums\UserRole;
use App\Models\AccessEntry;
use App\Models\ResidentVehicle;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    config(['seguria.ai.enabled' => false]);
    $this->site = Site::factory()->create();
    $this->resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id, 'name' => 'Martina Blá', 'lot' => '4C']);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
});

it('keeps each resident\'s cars and finds the owner by plate', function () {
    $this->actingAs($this->resident)->post('/vecinos/vehiculos', ['plate' => 'ad 789 gh', 'make_model' => 'Toyota Etios', 'color' => 'gris'])->assertSessionHasNoErrors();
    expect(ResidentVehicle::sole())->plate->toBe('AD789GH')->site_id->toBe($this->site->id);

    $this->actingAs($this->guard)->get('/search?q=AD789')->assertInertia(fn ($page) => $page->where('results.vehicles.0.owner', 'Martina Blá'));
    $this->actingAs($this->admin)->get('/team?q=ad789')->assertInertia(fn ($page) => $page->where('members.total', 1));
    $this->actingAs($this->admin)->get("/sites/{$this->site->id}?tab=vecinos")->assertInertia(fn ($page) => $page->where('residents.0.vehicles.0.plate', 'AD789GH'));
});

it('recognizes a resident\'s car at the gate', function () {
    ResidentVehicle::create(['user_id' => $this->resident->id, 'site_id' => $this->site->id, 'plate' => 'AD789GH']);

    $this->actingAs($this->guard)->post("/access/{$this->site->id}/visits", ['unit' => '9A', 'visiting' => 'Pedro', 'name' => 'Hermano de Martina', 'plate' => 'AD789GH']);

    expect(AccessEntry::sole()->reason)->toContain('Auto de Martina Blá (4C)');
});
