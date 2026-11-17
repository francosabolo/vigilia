<?php

use App\Enums\IncidentSource;
use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Patrol;
use App\Models\Site;
use App\Models\User;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Patrol\PatrolInspector;
use App\Services\Patrol\VirtualPatrol;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/** Answers from a script per camera name, so no test calls the API. */
function fakeInspector(array $answers): void
{
    app()->instance(PatrolInspector::class, new class($answers) implements PatrolInspector
    {
        public array $seen = [];

        public function __construct(private array $answers) {}

        public function inspect(Camera $camera, string $current, ?string $reference, string $context): array
        {
            $this->seen[] = ['camera' => $camera->name, 'has_reference' => $reference !== null, 'context' => $context];

            return ($this->answers[$camera->name] ?? null) ?? ['has_novelty' => false, 'kind' => 'ninguno', 'severity' => 'baja', 'description' => 'Todo normal.', 'confidence' => 0.9, 'model' => 'fake'];
        }
    });
}

function frameFor(Camera $camera, bool $fresh = true): void
{
    $image = imagecreatetruecolor(64, 36);
    imagefilledrectangle($image, 0, 0, 63, 35, imagecolorallocate($image, 120, 120, 120));
    ob_start();
    imagejpeg($image);
    Storage::disk(config('seguria.media_disk'))->put(app(CameraViewMonitor::class)->latestPath($camera), ob_get_clean());
    $camera->forceFill(['is_online' => true, 'view_checked_at' => $fresh ? now()->subMinutes(2) : now()->subHour()])->save();
}

beforeEach(function () {
    Storage::fake(config('seguria.media_disk'));
    config(['seguria.ai.enabled' => false]);
    $this->organization = Organization::factory()->create();
    $this->site = Site::factory()->create(['organization_id' => $this->organization->id]);
    $this->gate = Camera::factory()->create(['site_id' => $this->site->id, 'name' => 'Portón']);
    $this->hall = Camera::factory()->create(['site_id' => $this->site->id, 'name' => 'Hall']);
    $this->roof = Camera::factory()->create(['site_id' => $this->site->id, 'name' => 'Terraza']);
});

it('reports only what is out of place and sends medium findings to the guard as one incident', function () {
    Event::fake([IncidentOpened::class]);
    frameFor($this->gate);
    frameFor($this->hall);
    frameFor($this->roof, fresh: false);
    fakeInspector(['Portón' => ['has_novelty' => true, 'kind' => 'porton_abierto', 'severity' => 'media', 'description' => 'El portón quedó abierto.', 'confidence' => 0.9, 'model' => 'fake']]);

    $patrols = app(VirtualPatrol::class);
    $patrol = $patrols->run(Patrol::create(['site_id' => $this->site->id, 'trigger' => 'manual', 'started_at' => now(), 'cameras_total' => 3]));

    expect($patrol->status)->toBe('done')
        ->and($patrol->cameras_checked)->toBe(2)
        ->and($patrol->findings)->toHaveCount(1)
        ->and($patrol->skipped[0]['camera'])->toBe('Terraza')
        ->and($patrol->summary)->toBe('1 novedad en 2 cámaras; 1 sin revisar.');

    $incident = Incident::sole();
    expect($incident->source)->toBe(IncidentSource::Patrol)
        ->and($incident->title)->toBe('Ronda: portón o puerta abierta (Portón)')
        ->and($patrol->incident_id)->toBe($incident->id);
    Event::assertDispatched(IncidentOpened::class);
    Storage::disk(config('seguria.media_disk'))->assertExists($patrol->findings[0]['frame_path']);

    // Next round finds the same: it adds to the open incident instead of opening another one.
    $again = $patrols->run(Patrol::create(['site_id' => $this->site->id, 'trigger' => 'scheduled', 'started_at' => now(), 'cameras_total' => 3]));
    expect(Incident::count())->toBe(1)->and($again->incident_id)->toBe($incident->id)
        ->and($incident->actions()->where('type', 'patrol')->count())->toBe(2);
});

it('keeps minor findings in the round without bothering the guard', function () {
    frameFor($this->hall);
    fakeInspector(['Hall' => ['has_novelty' => true, 'kind' => 'objeto', 'severity' => 'baja', 'description' => 'Una caja junto al ascensor.', 'confidence' => 0.7, 'model' => 'fake']]);

    $patrol = app(VirtualPatrol::class)->run(Patrol::create(['site_id' => $this->site->id, 'trigger' => 'manual', 'started_at' => now()]));

    expect($patrol->findings)->toHaveCount(1)->and(Incident::count())->toBe(0);
});

it('starts automatic rounds only where they are turned on and due', function () {
    $this->site->update(['patrol_interval_minutes' => 30]);
    $off = Site::factory()->create(['organization_id' => $this->organization->id]);
    $recent = Site::factory()->create(['organization_id' => $this->organization->id, 'patrol_interval_minutes' => 60]);
    Patrol::create(['site_id' => $recent->id, 'trigger' => 'scheduled', 'started_at' => now()->subMinutes(20), 'status' => 'done']);

    expect(app(VirtualPatrol::class)->dueSites()->pluck('id')->all())->toBe([$this->site->id]);
});

it('lets the staff ask for a round now, one at a time, and shows it on the site', function () {
    fakeInspector([]);
    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->organization->id, 'site_id' => $this->site->id]);
    $resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->organization->id, 'site_id' => $this->site->id]);

    $this->actingAs($guard)->post("/sites/{$this->site->id}/patrols")->assertSessionHasNoErrors();
    expect(Patrol::sole()->requested_by)->toBe($guard->id);

    Patrol::query()->update(['status' => 'running']);
    $this->actingAs($guard)->post("/sites/{$this->site->id}/patrols")->assertSessionHasErrors('patrol');
    $this->actingAs($resident)->post("/sites/{$this->site->id}/patrols")->assertRedirect();
    expect(Patrol::count())->toBe(1);

    $this->actingAs($guard)->get("/sites/{$this->site->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->has('patrols', 1)->where('patrolIntervals', VirtualPatrol::INTERVALS));
});

it('lets the site managers set the automatic interval', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->organization->id]);

    $this->actingAs($admin)->patch("/sites/{$this->site->id}", ['ack_timeout_seconds' => 120, 'patrol_interval_minutes' => 30])->assertSessionHasNoErrors();
    expect($this->site->fresh()->patrol_interval_minutes)->toBe(30);

    $this->actingAs($admin)->patch("/sites/{$this->site->id}", ['ack_timeout_seconds' => 120, 'patrol_interval_minutes' => 7])->assertSessionHasErrors('patrol_interval_minutes');
    $this->actingAs($admin)->patch("/sites/{$this->site->id}", ['ack_timeout_seconds' => 120, 'patrol_interval_minutes' => null])->assertSessionHasNoErrors();
    expect($this->site->fresh()->patrol_interval_minutes)->toBeNull();
});
