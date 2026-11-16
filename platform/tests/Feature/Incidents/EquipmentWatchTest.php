<?php

use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->edge = EdgeDevice::factory()->create(['site_id' => $this->site->id, 'last_seen_at' => now()]);
});

it('opens an incident when an edge stops reporting and closes it when it comes back', function () {
    Event::fake([IncidentOpened::class]);

    $this->edge->update(['last_seen_at' => now()->subMinutes(10)]);
    $this->artisan('seguria:watch-equipment')->assertSuccessful();

    $incident = Incident::sole();
    expect($incident->source)->toBe(IncidentSource::Equipment)
        ->and($incident->edge_device_id)->toBe($this->edge->id)
        ->and($incident->title)->toContain('sin señal');
    Event::assertDispatched(IncidentOpened::class);

    // Still down: no duplicate incident.
    $this->artisan('seguria:watch-equipment')->assertSuccessful();
    expect(Incident::count())->toBe(1);

    $this->edge->update(['last_seen_at' => now()]);
    $this->artisan('seguria:watch-equipment')->assertSuccessful();

    expect($incident->fresh()->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->fresh()->resolution)->toBe(IncidentResolution::Recovered)
        ->and($this->edge->fresh()->offline_since)->toBeNull();
});

it('opens an incident for a camera without image after the grace period', function () {
    Event::fake([IncidentOpened::class]);
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'is_online' => false, 'last_seen_at' => now()->subMinute()]);

    $this->artisan('seguria:watch-equipment');
    expect(Incident::count())->toBe(0);

    $camera->update(['last_seen_at' => now()->subMinutes(5)]);
    $this->artisan('seguria:watch-equipment');

    expect(Incident::sole()->camera_id)->toBe($camera->id);
});

it('ignores simulated sites', function () {
    $this->site->update(['is_simulated' => true]);
    $this->edge->update(['last_seen_at' => now()->subHour()]);

    $this->artisan('seguria:watch-equipment');

    expect(Incident::count())->toBe(0);
});

it('lets staff close an equipment incident only as recovered', function () {
    $this->edge->update(['last_seen_at' => now()->subHour()]);
    Event::fake([IncidentOpened::class]);
    $this->artisan('seguria:watch-equipment');
    $incident = Incident::sole();
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->site->organization_id]);

    $this->actingAs($operator)->post("/incidents/{$incident->id}/resolve", ['resolution' => 'false_alarm'])->assertSessionHasErrors('resolution');
    $this->actingAs($operator)->post("/incidents/{$incident->id}/resolve", ['resolution' => 'recovered'])->assertSessionHasNoErrors();

    expect($incident->fresh()->resolution)->toBe(IncidentResolution::Recovered);
});
