<?php

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\GuardSighting;
use App\Models\Incident;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Notifications\GuardSupervisionAlert;
use App\Services\Guards\GuardWatch;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['seguria.ai.enabled' => false]);
    $this->site = Site::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
    $this->ana = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id, 'name' => 'Ana']);
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
});

it('measures response time per guard and per shift', function () {
    $night = now()->setTimezone('America/Argentina/Buenos_Aires')->subDay()->setTime(2, 0)->utc();
    $day = now()->setTimezone('America/Argentina/Buenos_Aires')->subDay()->setTime(14, 0)->utc();
    Incident::factory()->create(['site_id' => $this->site->id, 'detected_at' => $night, 'notified_at' => $night, 'acknowledged_at' => $night->copy()->addSeconds(60), 'acknowledged_by' => $this->ana->id]);
    Incident::factory()->create(['site_id' => $this->site->id, 'detected_at' => $day, 'notified_at' => $day, 'acknowledged_at' => $day->copy()->addSeconds(180), 'acknowledged_by' => $this->ana->id]);
    Incident::factory()->create(['site_id' => $this->site->id, 'detected_at' => $day, 'notified_at' => $day, 'escalated_at' => $day->copy()->addMinutes(3)]);

    $this->actingAs($this->admin)->get('/guards?days=7')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.guards.0.name', 'Ana')
            ->where('report.guards.0.answered', 2)
            ->where('report.guards.0.alerts', 3)
            ->where('report.guards.0.avg_ack_seconds', 120)
            ->where('report.guards.0.escalated', 1)
            ->where('report.shifts.night.avg_ack_seconds', 60)
            ->where('report.shifts.day.escalated', 1));

    $this->actingAs($this->ana)->get('/guards')->assertForbidden();
});

it('hands the shift over with a summary of what happened and what is still open', function () {
    Incident::factory()->create(['site_id' => $this->site->id, 'title' => 'Portón abierto', 'detected_at' => now()->subHour()]);

    $this->actingAs($this->ana)->post("/sites/{$this->site->id}/handover", ['notes' => 'El portón de servicio está trabado.'])->assertRedirect();

    $handover = ShiftHandover::sole();
    expect($handover->user_id)->toBe($this->ana->id)
        ->and($handover->open_incidents)->toBe(1)
        ->and($handover->summary)->toContain('Portón abierto');

    $this->actingAs($this->ana)->get('/dashboard')->assertInertia(fn ($page) => $page->where('lastHandover.notes', 'El portón de servicio está trabado.')->where('handoverSiteId', $this->site->id));
});

it('records a guard passing a round checkpoint and warns when a checkpoint goes unvisited', function () {
    Notification::fake();
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'is_checkpoint' => true, 'name' => 'Perímetro sur']);
    $event = DetectionEvent::factory()->create(['camera_id' => $camera->id, 'started_at' => now()->subHours(3), 'ai_verdict' => ['suspected_guard' => true, 'description' => 'Guardia con uniforme haciendo la ronda.']]);

    app(GuardWatch::class)->recordFromDetection($event);
    expect(GuardSighting::sole()->camera_id)->toBe($camera->id);

    $this->site->update(['round_interval_minutes' => 60, 'guard_monitoring_consent_at' => now()]);
    app(GuardWatch::class)->checkRounds($this->site->fresh('cameras'));

    Notification::assertSentTo($this->admin, GuardSupervisionAlert::class, fn ($n) => str_contains($n->title, 'Perímetro sur'));
    Notification::assertNotSentTo($this->ana, GuardSupervisionAlert::class);
});

it('asks for written consent before supervising guards', function () {
    $this->actingAs($this->admin)->patch("/sites/{$this->site->id}/guard-supervision", ['guard_post_max_absence_minutes' => 20, 'round_interval_minutes' => null])
        ->assertSessionHasErrors('consent');

    $this->actingAs($this->admin)->patch("/sites/{$this->site->id}/guard-supervision", ['guard_post_max_absence_minutes' => 20, 'round_interval_minutes' => 60, 'consent' => true])
        ->assertSessionHasNoErrors();

    expect($this->site->fresh())->guard_post_max_absence_minutes->toBe(20)->guard_monitoring_consent_at->not->toBeNull();
});
