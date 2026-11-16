<?php

use App\Enums\CameraViewStatus;
use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->incident = Incident::factory()->create(['site_id' => $this->site->id]);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => $this->site->id]);
    $this->operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->site->organization_id]);
});

it('lists only the incidents of accessible sites', function () {
    Incident::factory()->create(); // another organization

    $this->actingAs($this->guard)->get('/incidents')->assertInertia(fn (Assert $page) => $page
        ->component('incidents/index')
        ->has('incidents', 1)
        ->where('channel', "sites.{$this->site->id}"));

    $this->actingAs($this->operator)->get('/incidents')->assertInertia(fn (Assert $page) => $page
        ->has('incidents', 1)
        ->where('channel', "operators.{$this->site->organization_id}"));
});

it('forbids guards from other sites', function () {
    $stranger = User::factory()->create(['role' => UserRole::Guard, 'site_id' => Site::factory()->create()->id]);

    $this->actingAs($stranger)->get("/incidents/{$this->incident->id}")->assertForbidden();
    $this->actingAs($stranger)->post("/incidents/{$this->incident->id}/acknowledge")->assertForbidden();
});

it('lets the guard acknowledge and close an incident', function () {
    $this->actingAs($this->guard)->post("/incidents/{$this->incident->id}/acknowledge")->assertRedirect();
    expect($this->incident->fresh()->status)->toBe(IncidentStatus::Acknowledged);

    $this->actingAs($this->guard)->post("/incidents/{$this->incident->id}/resolve", ['resolution' => 'confirmed', 'notes' => 'Se llamó al 911'])->assertRedirect();

    $incident = $this->incident->fresh();
    expect($incident->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->resolution)->toBe(IncidentResolution::Confirmed)
        ->and($incident->actions()->where('type', 'confirmed')->value('notes'))->toBe('Se llamó al 911');
});

it('serves media only through signed urls', function () {
    Storage::fake('local');
    Storage::disk('local')->put('sites/1/events/1/snapshot.jpg', 'jpeg');
    $this->incident->update(['snapshot_path' => 'sites/1/events/1/snapshot.jpg']);

    $this->get("/media/incidents/{$this->incident->id}/snapshot")->assertForbidden();
    $this->get($this->incident->mediaUrl('snapshot'))->assertOk();
});

it('opens every kind of incident page', function (string $source) {
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'view_status' => CameraViewStatus::Moved]);
    $incident = Incident::factory()->create(['site_id' => $this->site->id, 'camera_id' => $camera->id, 'source' => $source]);

    $this->actingAs($this->operator)->get("/incidents/{$incident->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('incidents/show')->where('incident.source', $source));
})->with(['detection', 'resident_report', 'panic', 'tamper', 'equipment']);
