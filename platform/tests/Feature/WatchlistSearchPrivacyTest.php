<?php

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\ResidentContact;
use App\Models\Site;
use App\Models\User;

beforeEach(function () {
    config(['seguria.ai.enabled' => false]);
    $this->site = Site::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
});

it('alerts when a watched plate reaches the access', function () {
    $this->actingAs($this->admin)->post("/access/{$this->site->id}/watchlist", ['plate' => 'ab 123 cd', 'reason' => 'Robo del 10/11'])->assertRedirect();
    $this->actingAs($this->guard)->post("/access/{$this->site->id}/announce", [
        'resident_id' => User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id])->id,
        'name' => 'Desconocido', 'plate' => 'AB123CD',
    ]);

    expect(Incident::sole())->title->toBe('Patente en alerta en el acceso: AB123CD')->description->toContain('Robo del 10/11');
});

it('finds detections, incidents and entries by every word', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id]);
    DetectionEvent::factory()->create(['camera_id' => $camera->id, 'started_at' => now()->subDay(), 'status' => 'dismissed', 'ai_verdict' => ['description' => 'Hombre con campera roja caminando.']]);
    DetectionEvent::factory()->create(['camera_id' => $camera->id, 'started_at' => now()->subDay(), 'status' => 'dismissed', 'ai_verdict' => ['description' => 'Perro cruzando.']]);
    Incident::factory()->create(['site_id' => $this->site->id, 'title' => 'Persona con campera roja en el portón', 'detected_at' => now()]);

    $this->actingAs($this->guard)->get('/search?q=Campera+roja&days=7')->assertOk()
        ->assertInertia(fn ($page) => $page->has('results.detections', 1)->has('results.incidents', 1));
});

it('exports and erases a person\'s data on request', function () {
    $resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id, 'name' => 'Marta Ruiz']);
    ResidentContact::create(['user_id' => $resident->id, 'name' => 'Hija', 'phone' => '+5491155550000']);
    $report = Incident::factory()->create(['site_id' => $this->site->id, 'reported_by' => $resident->id]);

    $this->actingAs($this->admin)->get("/team/{$resident->id}/data")->assertOk()
        ->assertJsonPath('cuenta.name', 'Marta Ruiz')->assertJsonPath('contactos_de_emergencia.0.name', 'Hija');

    $this->actingAs($this->admin)->delete("/team/{$resident->id}/data", ['confirm' => 'otra'])->assertSessionHasErrors('confirm');
    $this->actingAs($this->admin)->delete("/team/{$resident->id}/data", ['confirm' => 'Marta Ruiz'])->assertRedirect('/team');

    expect(User::find($resident->id))->toBeNull()->and(ResidentContact::count())->toBe(0)->and($report->fresh()->reported_by)->toBeNull();
    $this->actingAs($this->guard)->get("/team/{$this->admin->id}/data")->assertNotFound();
});
