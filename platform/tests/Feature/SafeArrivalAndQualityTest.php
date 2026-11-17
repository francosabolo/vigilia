<?php

use App\Enums\DetectionStatus;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\SafeArrival;
use App\Models\Site;
use App\Models\User;
use App\Notifications\QualitySampleReady;
use App\Notifications\SafeArrivalNotice;
use App\Services\Rules\ArmingEvaluator;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->site = Site::factory()->create(['kind' => 'country']);
    $this->resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id, 'lot' => '42']);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->site->organization_id]);
});

it('tells the guard a resident is arriving and watches their gate at any hour', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id]);
    $gate = $camera->zones()->create(['name' => 'Portón lote 42', 'frigate_zone' => 'porton_42', 'kind' => 'door', 'lot' => '42', 'watched_labels' => ['person']]);
    $gate->armingSchedules()->create(['days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '03:00', 'ends_at' => '03:01']);
    $evaluator = app(ArmingEvaluator::class);
    $at = now()->setTimezone('America/Argentina/Buenos_Aires')->setTime(19, 0)->utc();
    $this->travelTo($at);

    expect($evaluator->isArmed($gate->fresh(), now(), 'America/Argentina/Buenos_Aires'))->toBeFalse();

    $this->actingAs($this->resident)->post('/vecinos/llegada', ['minutes' => 10])->assertRedirect();

    Notification::assertSentTo($this->guard, SafeArrivalNotice::class, fn ($n) => ! $n->overdue);
    Notification::assertNotSentTo($this->operator, SafeArrivalNotice::class);
    expect($evaluator->isArmed($gate->fresh(), now(), 'America/Argentina/Buenos_Aires'))->toBeTrue();

    $this->actingAs($this->guard)->get('/dashboard')->assertInertia(fn ($page) => $page->has('arrivals', 1));

    $arrival = SafeArrival::sole();
    $this->actingAs($this->resident)->post("/vecinos/llegada/{$arrival->id}/listo")->assertRedirect();
    expect($arrival->fresh()->status)->toBe('arrived');
});

it('warns the guard and the central when the resident never confirms', function () {
    $this->actingAs($this->resident)->post('/vecinos/llegada', ['minutes' => 5]);
    $this->travel(16)->minutes();

    $this->artisan('vigilia:check-arrivals')->assertSuccessful();

    Notification::assertSentTo([$this->guard, $this->operator], SafeArrivalNotice::class, fn ($n) => $n->overdue);
    expect(SafeArrival::sole()->status)->toBe('unconfirmed');
});

it('samples dismissed detections weekly and measures what the AI missed', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id]);
    DetectionEvent::factory()->count(25)->create(['camera_id' => $camera->id, 'status' => DetectionStatus::Dismissed, 'snapshot_path' => 'x.jpg', 'started_at' => now()->subDays(2)]);

    $this->artisan('vigilia:sample-dismissed')->assertSuccessful();
    expect(DetectionEvent::whereNotNull('qa_requested_at')->count())->toBe(20);
    Notification::assertSentTo($this->operator, QualitySampleReady::class);

    $sampled = DetectionEvent::whereNotNull('qa_requested_at')->get();
    $this->actingAs($this->operator)->post("/ai/quality/{$sampled[0]->id}", ['missed' => true])->assertRedirect();
    $this->actingAs($this->operator)->post("/ai/quality/{$sampled[1]->id}", ['missed' => false])->assertRedirect();

    $this->actingAs($this->operator)->get('/ai/quality')->assertOk()->assertInertia(fn ($page) => $page
        ->where('pendingCount', 18)
        ->where('stats.0.reviewed', 2)
        ->where('stats.0.miss_rate', 50));

    $this->actingAs($this->guard)->get('/ai/quality')->assertForbidden();
});
