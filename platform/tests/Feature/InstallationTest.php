<?php

use App\Enums\DetectionStatus;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\DetectionProcessor;

beforeEach(function () {
    config(['vigilia.ai.enabled' => false]);
    $this->site = Site::factory()->create(['perimeter' => [[-34.1, -58.1], [-34.1, -58.2], [-34.2, -58.2]]]);
    $this->edge = EdgeDevice::factory()->create(['site_id' => $this->site->id, 'last_seen_at' => now()]);
    $this->camera = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $this->edge->id, 'lat' => -34.15, 'lng' => -58.15]);
    $this->zone = $this->camera->zones()->create(['name' => 'Portón', 'frigate_zone' => 'porton', 'kind' => 'door', 'watched_labels' => ['person'], 'coordinates' => '0,0,1,0,1,1']);
    $this->zone->armingSchedules()->create(['days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '03:00', 'ends_at' => '03:01']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
});

it('analyzes detections during the walk test without alerting anyone, at any hour', function () {
    $this->actingAs($this->admin)->post("/sites/{$this->site->id}/test-mode")->assertRedirect();
    expect($this->site->fresh()->inTestMode())->toBeTrue();

    $event = DetectionEvent::factory()->create(['camera_id' => $this->camera->id, 'edge_device_id' => $this->edge->id, 'label' => 'person', 'zones' => ['porton'], 'status' => DetectionStatus::Pending]);
    app(DetectionProcessor::class)->process($event);

    expect($event->fresh())->is_test->toBeTrue()->status->toBe(DetectionStatus::Observed)
        ->and(Incident::count())->toBe(0);

    $this->actingAs($this->admin)->get("/sites/{$this->site->id}/setup")->assertOk()
        ->assertInertia(fn ($page) => $page->where('testResults.0.result', 'seen')
            ->where('steps', fn ($steps) => collect($steps)->firstWhere('id', 'test')['done'] === true));

    $this->actingAs($this->admin)->delete("/sites/{$this->site->id}/test-mode");
    expect($this->site->fresh()->inTestMode())->toBeFalse();
});

it('computes the checklist from the real state of the property', function () {
    $this->actingAs($this->admin)->get("/sites/{$this->site->id}/setup")
        ->assertInertia(fn ($page) => $page
            ->where('steps', fn ($steps) => collect($steps)->firstWhere('id', 'perimeter')['done'] === true
                && collect($steps)->firstWhere('id', 'edge_online')['done'] === true
                && collect($steps)->firstWhere('id', 'test')['done'] === false
                && collect($steps)->firstWhere('id', 'guard')['done'] === false));

    $this->site->update(['guard_whatsapp' => '+5491155550000']);
    $this->actingAs($this->admin)->get("/sites/{$this->site->id}")
        ->assertInertia(fn ($page) => $page->where('setup.total', 8)->where('setup.done', 7));
});

it('keeps the installation tools for the site managers', function () {
    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);

    $this->actingAs($guard)->get("/sites/{$this->site->id}/setup")->assertForbidden();
    $this->actingAs($guard)->post("/sites/{$this->site->id}/test-mode")->assertForbidden();
});
