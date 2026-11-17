<?php

use App\Enums\UserRole;
use App\Models\ResidentContact;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    $this->site = Site::factory()->create(['kind' => 'country']);
    $this->resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
});

it('lets the resident keep their emergency contacts', function () {
    $this->actingAs($this->resident)->post('/vecinos/contactos', ['name' => 'María', 'relation' => 'hija', 'phone' => '+5491155550001'])->assertSessionHasNoErrors();
    $this->actingAs($this->resident)->post('/vecinos/contactos', ['name' => 'Sin número', 'phone' => 'abc'])->assertSessionHasErrors('phone');

    $contact = ResidentContact::sole();
    expect($contact->user_id)->toBe($this->resident->id);

    $other = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->actingAs($other)->delete("/vecinos/contactos/{$contact->id}")->assertNotFound();
    $this->actingAs($this->resident)->delete("/vecinos/contactos/{$contact->id}")->assertRedirect();
    expect(ResidentContact::count())->toBe(0);
});

it('shows the contacts to the staff and lets the administration add them', function () {
    $this->actingAs($this->siteAdmin)->post("/sites/{$this->site->id}/residents/{$this->resident->id}/contacts", ['name' => 'Jorge', 'relation' => 'vecino', 'phone' => '+5491155550002'])->assertSessionHasNoErrors();

    $this->actingAs($this->siteAdmin)->get("/sites/{$this->site->id}?tab=vecinos")
        ->assertInertia(fn ($page) => $page->where('residents.0.contacts.0.name', 'Jorge'));

    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->actingAs($guard)->post("/sites/{$this->site->id}/residents/{$this->resident->id}/contacts", ['name' => 'X', 'phone' => '+5491155550003'])->assertForbidden();
});
