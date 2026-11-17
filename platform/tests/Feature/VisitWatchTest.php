<?php

use App\Enums\UserRole;
use App\Enums\ZoneKind;
use App\Jobs\CheckVisitorExit;
use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Notifications\VisitorLeftWithItems;
use App\Notifications\VisitorOverdue;
use App\Services\Access\AccessDesk;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Visits\ExitCheck;
use App\Services\Visits\VisitorMatch;
use App\Services\Visits\VisitWatch;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('vigilia.media_disk'));
    config(['vigilia.ai.enabled' => true]);
    $this->site = Site::factory()->create(['kind' => 'building']);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id, 'lot' => '4B']);
    $this->access = Camera::factory()->create(['site_id' => $this->site->id, 'is_access' => true]);
    Storage::disk(config('vigilia.media_disk'))->put(app(CameraViewMonitor::class)->latestPath($this->access), 'frame');
});

/** A VisitWatch whose AI answers with the given verdict. */
function visitWatchAnswering(mixed $verdict): VisitWatch
{
    return new class($verdict) extends VisitWatch
    {
        public function __construct(private mixed $verdict)
        {
            parent::__construct(app(App\Services\Verification\AiBudget::class));
        }

        protected function ask(array $content, string $format, string $system): mixed
        {
            return $this->verdict;
        }
    };
}

it('expects a visit to end in a few hours and a provider at the end of their hours', function () {
    $desk = app(AccessDesk::class);
    $visit = $desk->registerVisit($this->site, $this->guard, ['name' => 'Juan Gómez', 'unit' => '4B', 'resident_id' => $this->resident->id], false);
    expect($visit->expected_until->diffInHours(now(), absolute: true))->toBeGreaterThanOrEqual(3);

    $this->travelTo(now()->setTimezone($this->site->timezone ?? config('app.timezone'))->setTime(10, 0)->utc());
    $authorization = AccessAuthorization::create(['site_id' => $this->site->id, 'resident_id' => $this->resident->id, 'created_by' => $this->resident->id, 'kind' => 'staff', 'name' => 'Rosa', 'code' => '123456', 'token' => 'tok', 'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth(), 'time_from' => '08:00', 'time_to' => '13:00']);
    $staff = $desk->checkIn($this->site, '123456', $this->guard);

    expect($staff->authorization_id)->toBe($authorization->id)
        ->and($staff->expected_until->setTimezone($this->site->timezone ?? config('app.timezone'))->format('H:i'))->toBe('13:00');
});

it('tells the guard once when a visit is still inside after its time', function () {
    Notification::fake();
    $entry = AccessEntry::create(['site_id' => $this->site->id, 'kind' => 'visit', 'name' => 'Juan Gómez', 'unit' => '4B', 'status' => 'allowed', 'expected_until' => now()->subMinutes(10)]);

    $this->artisan('vigilia:watch-visits')->assertSuccessful();
    $this->artisan('vigilia:watch-visits')->assertSuccessful();

    Notification::assertSentToTimes($this->guard, VisitorOverdue::class, 1);
    expect($entry->fresh()->toPayload()['overdue'])->toBeTrue();
});

it('takes the exit picture and checks what the visitor carries out', function () {
    Queue::fake();
    $entry = app(AccessDesk::class)->registerVisit($this->site, $this->guard, ['name' => 'Juan Gómez', 'unit' => '4B', 'resident_id' => $this->resident->id], false);

    app(AccessDesk::class)->exit($entry);

    expect($entry->fresh()->exit_photo_path)->not->toBeNull();
    Queue::assertPushed(CheckVisitorExit::class);

    Notification::fake();
    $verdict = new ExitCheck;
    $verdict->carries_more = true;
    $verdict->description = 'una caja grande';
    visitWatchAnswering($verdict)->checkExit($entry->fresh());

    Notification::assertSentTo($this->resident, VisitorLeftWithItems::class);
    expect($entry->fresh()->toPayload()['left_with'])->toBe('una caja grande');
});

it('raises a visitor seen off their route, comparing with the entry photos', function () {
    $entry = app(AccessDesk::class)->registerVisit($this->site, $this->guard, ['name' => 'Juan Gómez', 'unit' => '4B', 'resident_id' => $this->resident->id], false);
    $terrace = Camera::factory()->create(['site_id' => $this->site->id]);
    $terrace->zones()->create(['name' => 'Tablero eléctrico', 'frigate_zone' => 'tablero', 'kind' => ZoneKind::Sensitive, 'watched_labels' => ['person']]);
    $terrace->zones()->create(['name' => 'Hall', 'frigate_zone' => 'hall', 'kind' => ZoneKind::CommonArea, 'watched_labels' => ['person']]);
    Storage::disk(config('vigilia.media_disk'))->put('x/det.jpg', 'snapshot');
    $verdict = new VisitorMatch;
    $verdict->match = 1;
    $verdict->confidence = 0.8;
    $verdict->description = 'Abre el tablero eléctrico.';

    // In the hall (on the way to the unit) nothing is checked.
    $hall = DetectionEvent::factory()->create(['camera_id' => $terrace->id, 'zones' => ['hall'], 'snapshot_path' => 'x/det.jpg']);
    expect(visitWatchAnswering($verdict)->checkRoute($hall))->toBeNull();

    $board = DetectionEvent::factory()->create(['camera_id' => $terrace->id, 'zones' => ['tablero'], 'snapshot_path' => 'x/det.jpg']);
    $incident = visitWatchAnswering($verdict)->checkRoute($board);

    expect($incident)->toBeInstanceOf(Incident::class)
        ->and($incident->title)->toContain('Visita fuera de su recorrido')->toContain('Juan Gómez')
        ->and($incident->actions()->where('type', 'visitor_match')->value('meta')['access_entry_id'])->toBe($entry->id);
});

it('does not raise anything when no visitor matches the person', function () {
    app(AccessDesk::class)->registerVisit($this->site, $this->guard, ['name' => 'Juan Gómez', 'unit' => '4B'], false);
    $camera = Camera::factory()->create(['site_id' => $this->site->id]);
    $camera->zones()->create(['name' => 'Perímetro', 'frigate_zone' => 'perimetro', 'kind' => ZoneKind::Perimeter, 'watched_labels' => ['person']]);
    Storage::disk(config('vigilia.media_disk'))->put('x/det.jpg', 'snapshot');
    $none = new VisitorMatch;
    $none->match = 0;
    $none->confidence = 0.9;
    $none->description = '';

    $event = DetectionEvent::factory()->create(['camera_id' => $camera->id, 'zones' => ['perimetro'], 'snapshot_path' => 'x/det.jpg']);

    expect(visitWatchAnswering($none)->checkRoute($event))->toBeNull()
        ->and(Incident::count())->toBe(0);
});
