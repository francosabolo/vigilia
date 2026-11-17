<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->organization->id]);
});

it('walks the admin from a new property to the next steps', function () {
    $this->actingAs($this->admin)->get('/onboarding')->assertOk()->assertInertia(fn ($page) => $page->component('onboarding/show')->where('step', 'propiedad'));

    $response = $this->actingAs($this->admin)->post('/sites', ['name' => 'Condominio Las Glorias', 'kind' => 'condominium', 'address' => 'Ruta 26 km 5']);
    $site = Site::where('name', 'Condominio Las Glorias')->sole();
    $response->assertRedirect("/sites/{$site->id}/onboarding/ubicacion");

    foreach (['ubicacion', 'equipo', 'camaras', 'guardia', 'vecinos', 'prueba'] as $step) {
        $this->actingAs($this->admin)->get("/sites/{$site->id}/onboarding/{$step}")->assertOk()->assertInertia(fn ($page) => $page->where('step', $step));
    }

    $this->actingAs($this->admin)->post("/sites/{$site->id}/edges", ['name' => 'edge-guardia'])->assertSessionHas('new_edge');
});

it('invites many residents at once from a pasted list and reports the bad lines', function () {
    Notification::fake();
    $site = Site::factory()->create(['organization_id' => $this->organization->id]);
    User::factory()->create(['email' => 'ya@ejemplo.com']);

    $this->actingAs($this->admin)->post("/sites/{$site->id}/residents/import", ['lines' => implode("\n", [
        'Marta Ruiz, marta@ejemplo.com, 4B, +54 9 11 5555-0000',
        "Juan Pérez\tjuan@ejemplo.com\t7A",
        'Sin email',
        'Repetido; ya@ejemplo.com; 1A',
    ])])->assertSessionHas('notice', fn ($text) => str_starts_with($text, '2 vecinos invitados.') && str_contains($text, 'Línea 3') && str_contains($text, 'Línea 4'));

    $marta = User::where('email', 'marta@ejemplo.com')->sole();
    expect($marta)->role->toBe(UserRole::Resident)->lot->toBe('4B')->phone->toBe('+5491155550000')->site_id->toBe($site->id);
    Notification::assertSentTo($marta, ResetPassword::class);
});
