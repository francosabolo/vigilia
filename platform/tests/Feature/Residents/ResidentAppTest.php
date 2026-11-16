<?php

use App\Enums\IncidentSource;
use App\Enums\Severity;
use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Listeners\NotifyResidentsOfSector;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Site;
use App\Models\SnapshotRequest;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\ResidentSectorNotice;
use App\Services\Rules\ArmingEvaluator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->resident = User::factory()->create([
        'role' => UserRole::Resident, 'site_id' => $this->site->id, 'organization_id' => $this->site->organization_id,
        'lot' => '42', 'sector' => 'Norte',
    ]);
});

it('keeps residents out of the console and staff out of the residents app', function () {
    $incident = Incident::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->resident)->get('/dashboard')->assertRedirect('/vecinos');
    $this->actingAs($this->resident)->get("/incidents/{$incident->id}")->assertRedirect('/vecinos');
    $this->actingAs($this->resident)->get('/cameras')->assertRedirect('/vecinos');
    $this->actingAs($this->resident)->get('/vecinos')->assertOk()->assertInertia(fn (Assert $page) => $page->component('residents/home'));

    $guard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => $this->site->id]);
    $this->actingAs($guard)->get('/vecinos')->assertRedirect('/dashboard');
});

it('opens a critical incident when a resident presses the panic button', function () {
    Event::fake([IncidentOpened::class]);

    $this->actingAs($this->resident)->post('/vecinos/panic')->assertRedirect();

    $incident = Incident::sole();
    expect($incident->source)->toBe(IncidentSource::Panic)
        ->and($incident->severity)->toBe(Severity::Critical)
        ->and($incident->reported_by)->toBe($this->resident->id)
        ->and($incident->title)->toContain('lote 42');
    Event::assertDispatched(IncidentOpened::class);
});

it('asks the edge for live frames of the reported sector', function () {
    Event::fake([IncidentOpened::class]);
    $edge = EdgeDevice::factory()->create(['site_id' => $this->site->id]);
    $north = Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $edge->id, 'sector' => 'Norte']);
    Camera::factory()->create(['site_id' => $this->site->id, 'edge_device_id' => $edge->id, 'sector' => 'Sur']);

    $this->actingAs($this->resident)->post('/vecinos/report', ['sector' => 'Norte', 'notes' => 'Alguien saltó el alambrado'])->assertRedirect();

    $incident = Incident::sole();
    expect($incident->source)->toBe(IncidentSource::ResidentReport)
        ->and($incident->sector)->toBe('Norte')
        ->and(SnapshotRequest::pluck('camera_id')->all())->toBe([$north->id]);

    $this->actingAs($this->resident)->post('/vecinos/report', ['sector' => 'Inexistente'])->assertSessionHasErrors('sector');
});

it('arms the zones of a lot while its resident is away', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id]);
    $zone = Zone::factory()->for($camera)->create(['lot' => '42']);
    $zone->armingSchedules()->create(['days' => [1], 'starts_at' => '00:00', 'ends_at' => '00:01']);
    $sunday = now()->next('Sunday')->setTime(15, 0);

    expect(app(ArmingEvaluator::class)->isArmed($zone->fresh(), $sunday, 'UTC'))->toBeFalse();

    $this->actingAs($this->resident)->put('/vecinos/away', ['away_until' => $sunday->copy()->addDay()->toDateString()])->assertRedirect();

    expect(app(ArmingEvaluator::class)->isArmed($zone->fresh(), $sunday, 'UTC'))->toBeTrue();
});

it('notifies residents of the sector without images, except the reporter', function () {
    Notification::fake();
    $neighbor = User::factory()->create(['role' => UserRole::Resident, 'site_id' => $this->site->id, 'sector' => 'Norte']);
    $otherSector = User::factory()->create(['role' => UserRole::Resident, 'site_id' => $this->site->id, 'sector' => 'Sur']);
    $neighbor->updatePushSubscription('https://push.example.com/1', 'key', 'token');
    $incident = Incident::factory()->create(['site_id' => $this->site->id, 'sector' => 'Norte', 'reported_by' => $this->resident->id, 'snapshot_path' => 'x.jpg']);

    app(NotifyResidentsOfSector::class)->handleOpened(new IncidentOpened($incident));

    Notification::assertSentTo($neighbor, ResidentSectorNotice::class, function (ResidentSectorNotice $notice) use ($neighbor) {
        $message = $notice->toWebPush($neighbor, $notice)->toArray();

        return $message['title'] === 'Alerta en el sector Norte' && ! isset($message['image']);
    });
    Notification::assertNotSentTo($this->resident, ResidentSectorNotice::class);
    Notification::assertNotSentTo($otherSector, ResidentSectorNotice::class);
});

it('lets the site administration invite residents', function () {
    Notification::fake();
    $siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin, 'site_id' => $this->site->id]);
    $guard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => $this->site->id]);

    $this->actingAs($guard)->post("/sites/{$this->site->id}/residents", ['name' => 'Ana', 'email' => 'ana@example.com', 'lot' => '7'])->assertForbidden();
    $this->actingAs($siteAdmin)->post("/sites/{$this->site->id}/residents", ['name' => 'Ana', 'email' => 'ana@example.com', 'lot' => '7', 'sector' => 'Norte'])->assertRedirect();

    $ana = User::where('email', 'ana@example.com')->sole();
    expect($ana->role)->toBe(UserRole::Resident)->and($ana->site_id)->toBe($this->site->id);
    Notification::assertSentTo($ana, ResetPassword::class);
});

it('lets the site administration edit a resident', function () {
    $siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin, 'site_id' => $this->site->id]);

    $this->actingAs($siteAdmin)->patch("/sites/{$this->site->id}/residents/{$this->resident->id}", [
        'name' => 'Laura Gómez', 'lot' => '43', 'sector' => 'Sur', 'phone' => '+5491155550001',
    ])->assertRedirect();

    expect($this->resident->fresh()->only('lot', 'sector', 'phone'))->toBe(['lot' => '43', 'sector' => 'Sur', 'phone' => '+5491155550001']);

    $otherSite = Site::factory()->create();
    $this->actingAs($siteAdmin)->patch("/sites/{$otherSite->id}/residents/{$this->resident->id}", ['name' => 'X', 'lot' => '1'])->assertForbidden();
});
