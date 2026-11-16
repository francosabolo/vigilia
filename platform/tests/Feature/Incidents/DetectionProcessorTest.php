<?php

use App\Enums\DetectionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\ZoneKind;
use App\Events\DetectionActivity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\ArmingSchedule;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\User;
use App\Models\Zone;
use App\Services\Incidents\DetectionProcessor;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\Event;

function fakeVerdict(bool $isThreat = true, ?float $confidence = 0.9, int $objects = 1): void
{
    app()->instance(DetectionVerifier::class, new class($isThreat, $confidence, $objects) implements DetectionVerifier
    {
        public function __construct(private bool $isThreat, private ?float $confidence, private int $objects) {}

        public function verify(DetectionEvent $event): VerificationResult
        {
            return new VerificationResult($this->isThreat, $this->confidence, 'Una persona trepando el alambrado.', $this->objects);
        }

        public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
        {
            return new VerificationResult($this->isThreat, $this->confidence, 'Captura.');
        }
    });
}

function detection(Camera $camera, array $attributes = []): DetectionEvent
{
    return DetectionEvent::factory()->create([
        'edge_device_id' => EdgeDevice::factory()->create(['site_id' => $camera->site_id])->id,
        'camera_id' => $camera->id,
        'zones' => ['alambrado'],
        'started_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function () {
    Event::fake([IncidentOpened::class, IncidentChanged::class]);
    $this->camera = Camera::factory()->create(['name' => 'Perímetro Norte']);
    $this->zone = Zone::factory()->for($this->camera)->create(['name' => 'Alambrado', 'frigate_zone' => 'alambrado']);
});

it('opens an incident for a verified detection in an armed zone', function () {
    fakeVerdict();
    $event = detection($this->camera);

    $incident = app(DetectionProcessor::class)->process($event);

    expect($incident)->toBeInstanceOf(Incident::class)
        ->and($incident->status)->toBe(IncidentStatus::New)
        ->and($incident->severity)->toBe(Severity::High)
        ->and($incident->title)->toBe('Persona en Alambrado (Perímetro Norte)')
        ->and($incident->description)->toBe('Una persona trepando el alambrado.')
        ->and($event->fresh()->status)->toBe(DetectionStatus::Threat)
        ->and($incident->actions()->where('type', 'detected')->exists())->toBeTrue();

    Event::assertDispatched(IncidentOpened::class);
});

it('marks several people as critical', function () {
    fakeVerdict(objects: 3);

    expect(app(DetectionProcessor::class)->process(detection($this->camera))->severity)->toBe(Severity::Critical);
});

it('ignores detections outside watched zones or disarmed hours without calling the AI', function () {
    app()->instance(DetectionVerifier::class, Mockery::mock(DetectionVerifier::class)->shouldNotReceive('verify')->getMock());

    $outside = detection($this->camera, ['zones' => ['vereda']]);
    expect(app(DetectionProcessor::class)->process($outside))->toBeNull()
        ->and($outside->fresh()->status)->toBe(DetectionStatus::Ignored)
        ->and($outside->fresh()->ai_verdict)->toBe(['skipped' => 'outside_watched_zone']);

    $this->travelTo(now('America/Argentina/Buenos_Aires')->setTime(12, 0));
    ArmingSchedule::factory()->for($this->zone)->create(['days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '22:00', 'ends_at' => '06:00']);
    $midday = detection($this->camera);

    expect(app(DetectionProcessor::class)->process($midday))->toBeNull()
        ->and($midday->fresh()->ai_verdict)->toBe(['skipped' => 'zone_disarmed']);
});

it('dismisses detections the AI rejects or is not confident about', function () {
    fakeVerdict(isThreat: false);
    $animal = detection($this->camera);
    expect(app(DetectionProcessor::class)->process($animal))->toBeNull()
        ->and($animal->fresh()->status)->toBe(DetectionStatus::Dismissed);

    fakeVerdict(confidence: 0.2);
    expect(app(DetectionProcessor::class)->process(detection($this->camera)))->toBeNull();

    expect(Incident::count())->toBe(0);
});

it('groups detections of the same zone within the dedup window', function () {
    fakeVerdict();
    $first = app(DetectionProcessor::class)->process(detection($this->camera, ['started_at' => now()]));
    $second = app(DetectionProcessor::class)->process(detection($this->camera, ['started_at' => now()->addSeconds(60)]));
    $later = app(DetectionProcessor::class)->process(detection($this->camera, ['started_at' => now()->addMinutes(10)]));

    expect($second->id)->toBe($first->id)
        ->and($later->id)->not->toBe($first->id)
        ->and($first->detectionEvents()->count())->toBe(2);

    Event::assertDispatchedTimes(IncidentOpened::class, 2);
});

it('watches the whole frame when the camera has no zones', function () {
    fakeVerdict();
    $camera = Camera::factory()->create();

    expect(app(DetectionProcessor::class)->process(detection($camera, ['zones' => []])))->toBeInstanceOf(Incident::class);
});

it('treats a person at a door, window or sensitive point as critical', function () {
    fakeVerdict();
    $this->zone->update(['kind' => ZoneKind::Door, 'name' => 'Puerta de servicio']);

    $incident = app(DetectionProcessor::class)->process(detection($this->camera));

    expect($incident->severity)->toBe(Severity::Critical)
        ->and($incident->title)->toBe('Persona en Puerta de servicio (Perímetro Norte)');
});

it('records how each detection was decided and broadcasts it live', function () {
    Event::fake([IncidentOpened::class, IncidentChanged::class, DetectionActivity::class]);
    fakeVerdict();

    $alert = detection($this->camera);
    $incident = app(DetectionProcessor::class)->process($alert);

    expect(collect($alert->fresh()->trace)->pluck('step')->all())->toBe(['detector', 'zone', 'schedule', 'ai', 'decision'])
        ->and(collect($alert->fresh()->trace)->last()['detail'])->toContain("incidente #{$incident->id}");

    $outside = detection($this->camera, ['zones' => ['vereda']]);
    app(DetectionProcessor::class)->process($outside);
    expect(collect($outside->fresh()->trace)->last())->toMatchArray(['step' => 'zone', 'result' => 'stop']);

    // "Analyzing" first, then the decision, for the alert; only the decision for the skipped one.
    Event::assertDispatched(DetectionActivity::class, fn ($e) => $e->item['id'] === $alert->id && $e->item['outcome'] === 'analyzing');
    Event::assertDispatched(DetectionActivity::class, fn ($e) => $e->item['id'] === $alert->id && $e->item['outcome'] === 'alert' && str_starts_with($e->item['headline'], 'Alerta:'));
    Event::assertDispatched(DetectionActivity::class, fn ($e) => $e->item['id'] === $outside->id && $e->item['outcome'] === 'ignored');
});

it('shows the AI activity on the dashboard and the decision trail in the incident', function () {
    fakeVerdict();
    $incident = app(DetectionProcessor::class)->process(detection($this->camera));
    $operator = User::factory()->create(['role' => 'operator', 'organization_id' => $this->camera->site->organization_id]);

    $this->actingAs($operator)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->has('activity', 1)
        ->where('activity.0.outcome', 'alert')
        ->has('activity.0.trace', 5));

    $this->actingAs($operator)->get("/incidents/{$incident->id}")->assertInertia(fn ($page) => $page
        ->has('incident.decisions', 1)
        ->where('incident.decisions.0.agent', 'Analista IA'));
});
