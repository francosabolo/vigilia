<?php

use App\Enums\UserRole;
use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Models\Site;
use App\Models\User;
use App\Notifications\VisitorAtGate;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['seguria.ai.enabled' => false]);
    Notification::fake();
    $this->site = Site::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->resident = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id, 'lot' => '12']);
});

it('lets a resident invite a visit and the guard let it in with the code', function () {
    $this->actingAs($this->resident)->post('/vecinos/visitas', [
        'kind' => 'visit', 'name' => 'Juan Pérez', 'valid_from' => now('America/Argentina/Buenos_Aires')->toDateString(), 'valid_until' => now('America/Argentina/Buenos_Aires')->toDateString(),
    ])->assertSessionHasNoErrors();

    $visit = AccessAuthorization::sole();
    expect($visit->code)->toHaveLength(6)->and($visit->resident_id)->toBe($this->resident->id);
    $this->actingAs($this->resident)->get("/vecinos/visitas/{$visit->id}/qr")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

    $this->actingAs($this->guard)->post("/access/{$this->site->id}/check-in", ['code' => $visit->code, 'plate' => 'ab123cd'])->assertSessionHas('notice', 'Puede pasar: Juan Pérez.');

    expect(AccessEntry::sole())->status->toBe('allowed')->plate->toBe('AB123CD');
    Notification::assertSentTo($this->resident, VisitorAtGate::class);
});

it('turns away codes that do not exist, expired or out of hours', function () {
    $this->actingAs($this->guard)->post("/access/{$this->site->id}/check-in", ['code' => '999999'])->assertSessionHas('notice', fn ($text) => str_starts_with($text, 'No puede pasar'));

    $provider = AccessAuthorization::create([
        'site_id' => $this->site->id, 'kind' => 'provider', 'name' => 'Jardinero', 'code' => '123456', 'token' => (string) Str::uuid(),
        'valid_from' => now()->subWeek()->toDateString(), 'valid_until' => now()->addWeek()->toDateString(),
        'days' => [now('America/Argentina/Buenos_Aires')->addDay()->isoWeekday()],
    ]);
    expect($provider->refusal(now()))->toBe('Hoy no es un día autorizado.');

    $provider->update(['days' => null, 'revoked_at' => now()]);
    expect($provider->fresh()->refusal(now()))->toBe('La autorización fue cancelada.');
});

it('asks the resident about a visitor without notice and lets the answer through', function () {
    $this->actingAs($this->guard)->post("/access/{$this->site->id}/announce", ['resident_id' => $this->resident->id, 'name' => 'Delivery'])->assertSessionHasNoErrors();

    $entry = AccessEntry::sole();
    expect($entry->status)->toBe('waiting');
    Notification::assertSentTo($this->resident, VisitorAtGate::class, fn ($n) => $n->inbox($this->resident)['level'] === 'alarm');

    $other = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $this->actingAs($other)->post("/vecinos/accesos/{$entry->id}/respuesta", ['allow' => true])->assertNotFound();

    $this->actingAs($this->resident)->post("/vecinos/accesos/{$entry->id}/respuesta", ['allow' => true])->assertRedirect();
    expect($entry->fresh()->status)->toBe('allowed');
});

it('keeps the gate screen for the staff of the property', function () {
    $outsider = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id]);

    $this->actingAs($this->guard)->get('/access')->assertOk()->assertInertia(fn ($page) => $page->where('site.id', $this->site->id));
    $this->actingAs($outsider)->post("/access/{$this->site->id}/check-in", ['code' => '123456'])->assertForbidden();
});

it('registers a visit to a unit and whom, marks the exit and filters the log by unit', function () {
    $this->actingAs($this->guard)->post("/access/{$this->site->id}/visits", [
        'unit' => '12', 'resident_id' => $this->resident->id, 'name' => 'Juan Gómez', 'document' => '30111222', 'plate' => 'ac456ef', 'purpose' => 'visita',
    ])->assertSessionHas('notice', fn ($text) => str_contains($text, 'Juan Gómez a 12'));

    $entry = AccessEntry::sole();
    expect($entry)->status->toBe('allowed')->unit->toBe('12')->visiting->toBe($this->resident->name)->plate->toBe('AC456EF');
    Notification::assertSentTo($this->resident, VisitorAtGate::class);

    $this->actingAs($this->guard)->get('/access')->assertInertia(fn ($page) => $page->has('inside', 1));
    $this->actingAs($this->guard)->post("/access/entries/{$entry->id}/exit")->assertRedirect();
    expect($entry->fresh()->exited_at)->not->toBeNull();

    $this->actingAs($this->guard)->post("/access/{$this->site->id}/visits", ['unit' => '7B', 'visiting' => 'Martina Blá', 'name' => 'Ana Ruiz']);
    $this->actingAs($this->guard)->get('/access?unit=7B')->assertInertia(fn ($page) => $page->has('entries', 1)->where('entries.0.visiting', 'Martina Blá'));
    $this->actingAs($this->guard)->get('/access?q=ac456')->assertInertia(fn ($page) => $page->has('entries', 1)->where('entries.0.name', 'Juan Gómez'));
});
