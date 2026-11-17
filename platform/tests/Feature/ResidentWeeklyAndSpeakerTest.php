<?php

use App\Console\Commands\SendResidentWeeklySummary;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DeterrenceCommand;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ResidentWeeklySummary;
use Illuminate\Support\Facades\Notification;

it('sends residents one sentence about their week', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $site->organization_id, 'site_id' => $site->id]);

    $this->artisan('vigilia:resident-weekly')->assertSuccessful();

    Notification::assertSentTo($resident, ResidentWeeklySummary::class, fn ($n) => str_contains($n->summary, 'semana tranquila'));
    expect(SendResidentWeeklySummary::sentence(['detections' => 40, 'filtered_by_ai' => 30, 'outside_schedule' => 5, 'incidents' => 3, 'confirmed' => 1]))
        ->toContain('frenó 1 intrusión');
});

it('sends the operator message and the speaker test to the edge', function () {
    $site = Site::factory()->create();
    $edge = EdgeDevice::factory()->create(['site_id' => $site->id]);
    $token = $edge->issueToken();
    $camera = Camera::factory()->create(['site_id' => $site->id, 'edge_device_id' => $edge->id, 'frigate_name' => 'porton']);
    $incident = Incident::factory()->create(['site_id' => $site->id, 'camera_id' => $camera->id]);
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $site->organization_id]);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $site->organization_id]);

    $this->actingAs($operator)->post("/incidents/{$incident->id}/speaker", ['message' => 'Retírese de la propiedad.'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post("/cameras/{$camera->id}/speaker-test")->assertRedirect();

    $commands = $this->withToken($token)->getJson('/api/edge/commands')->assertOk()->json('deterrence');
    expect($commands)->toHaveCount(2)->and($incident->actions()->where('type', 'speaker_message')->exists())->toBeTrue();

    $test = DeterrenceCommand::whereNull('incident_id')->sole();
    $this->withToken($token)->postJson("/api/edge/deterrence/{$test->id}", ['ok' => true, 'result' => 'Aviso reproducido.'])->assertOk();
    expect($test->fresh()->status)->toBe('done');
});
