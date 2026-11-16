<?php

use App\Enums\DetectionStatus;
use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Enums\ZoneAutonomy;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\DeterrenceCommand;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\ZoneAutonomyDemoted;
use App\Services\Incidents\DetectionProcessor;
use App\Services\Incidents\IncidentWorkflow;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Event::fake([IncidentOpened::class, IncidentChanged::class]);
    $this->edge = EdgeDevice::factory()->create();
    $this->camera = Camera::factory()->create(['site_id' => $this->edge->site_id, 'edge_device_id' => $this->edge->id, 'frigate_name' => 'norte']);
    $this->zone = Zone::factory()->for($this->camera)->create(['name' => 'Alambrado', 'frigate_zone' => 'alambrado']);
    app()->instance(DetectionVerifier::class, new class implements DetectionVerifier
    {
        public function verify(DetectionEvent $event): VerificationResult
        {
            return new VerificationResult(true, 0.95, 'Persona trepando.');
        }

        public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
        {
            return new VerificationResult(true, 0.95, 'Persona trepando.');
        }
    });
});

function personAt(Camera $camera): DetectionEvent
{
    return DetectionEvent::factory()->create(['edge_device_id' => $camera->edge_device_id, 'camera_id' => $camera->id, 'label' => 'person', 'zones' => ['alambrado'], 'started_at' => now()]);
}

function closedAlerts(Zone $zone, int $confirmed, int $falseAlarms): void
{
    foreach ([IncidentResolution::Confirmed->value => $confirmed, IncidentResolution::FalseAlarm->value => $falseAlarms] as $resolution => $count) {
        Incident::factory()->count($count)->create([
            'site_id' => $zone->camera->site_id, 'camera_id' => $zone->camera_id, 'zone_id' => $zone->id,
            'status' => IncidentStatus::Resolved, 'resolution' => $resolution, 'resolved_at' => now()->subDays(2),
        ]);
    }
}

it('records but alerts nobody while the zone is in calibration', function () {
    $this->zone->update(['autonomy' => ZoneAutonomy::Calibration]);
    $event = personAt($this->camera);

    expect(app(DetectionProcessor::class)->process($event))->toBeNull()
        ->and($event->fresh()->status)->toBe(DetectionStatus::Observed)
        ->and(Incident::count())->toBe(0);
});

it('refuses automatic deterrence until the zone earned it', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->camera->site->organization_id]);

    $this->actingAs($admin)->patch("/zones/{$this->zone->id}/autonomy", ['autonomy' => 'deterrence'])->assertSessionHasErrors('autonomy');

    closedAlerts($this->zone, confirmed: 10, falseAlarms: 0);
    $this->actingAs($admin)->patch("/zones/{$this->zone->id}/autonomy", ['autonomy' => 'deterrence'])->assertSessionHasNoErrors();
    expect($this->zone->fresh()->autonomy)->toBe(ZoneAutonomy::Deterrence);
});

it('triggers the speaker on a clear threat and the edge reports it played', function () {
    $this->zone->update(['autonomy' => ZoneAutonomy::Deterrence]);
    $token = $this->edge->issueToken();

    $incident = app(DetectionProcessor::class)->process(personAt($this->camera));
    $command = DeterrenceCommand::sole();
    expect($command->incident_id)->toBe($incident->id)->and($incident->actions()->where('type', 'deterred')->exists())->toBeTrue();

    $this->withToken($token)->getJson('/api/edge/commands')->assertJsonPath('deterrence.0.camera', 'norte');
    $this->withToken($token)->postJson("/api/edge/deterrence/{$command->id}", ['ok' => true, 'result' => 'Aviso reproducido.'])->assertOk();

    expect($command->fresh()->status)->toBe('done')
        ->and($incident->actions()->where('type', 'deterrence_done')->exists())->toBeTrue();
});

it('demotes the zone after two deterrences closed as false alarms', function () {
    Notification::fake();
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->camera->site->organization_id]);
    $this->zone->update(['autonomy' => ZoneAutonomy::Deterrence]);

    foreach (range(1, 2) as $i) {
        $incident = app(DetectionProcessor::class)->process(personAt($this->camera));
        app(IncidentWorkflow::class)->resolve($incident, IncidentResolution::FalseAlarm, $admin, 'web');
    }

    expect($this->zone->fresh()->autonomy)->toBe(ZoneAutonomy::Assisted);
    Notification::assertSentTo($admin, ZoneAutonomyDemoted::class);
});
