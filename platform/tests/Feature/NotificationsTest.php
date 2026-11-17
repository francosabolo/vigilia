<?php

use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Models\Client;
use App\Models\Incident;
use App\Models\ResidentMessage;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AiBudgetReached;
use App\Notifications\IncidentAlert;
use App\Notifications\IncidentNotice;
use App\Notifications\StaffMessageToResident;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->client = Client::factory()->create();
    $this->site = Site::factory()->create(['organization_id' => $this->client->organization_id, 'client_id' => $this->client->id]);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id, 'phone' => '+5491155550000']);
});

it('puts every notification in the inbox and lets the person drop external channels', function () {
    $incident = Incident::factory()->create(['site_id' => $this->site->id, 'title' => 'Persona en el portón']);

    expect((new IncidentAlert($incident))->via($this->guard))->toContain('database', 'broadcast', App\Notifications\Channels\WhatsAppChannel::class);

    $this->guard->update(['notification_prefs' => ['email' => false, 'whatsapp' => false, 'push' => true]]);
    expect((new IncidentAlert($incident))->via($this->guard->fresh()))->toBe(['database', 'broadcast'])
        ->and((new AiBudgetReached($this->site, 10))->via($this->guard->fresh()))->toBe(['database', 'broadcast']);
});

it('shows the inbox, opens a notification and marks everything read', function () {
    $incident = Incident::factory()->create(['site_id' => $this->site->id, 'title' => 'Persona en el portón']);
    $this->guard->notifyNow(new IncidentAlert($incident), ['database']);
    $this->travel(1)->minute();
    $this->guard->notifyNow(new IncidentAlert($incident, escalated: true), ['database']);

    $this->actingAs($this->guard)->getJson('/notifications/latest')->assertOk()
        ->assertJsonPath('unread', 2)
        ->assertJsonPath('items.0.title', 'Escalado a la central: Persona en el portón');

    $first = $this->guard->notifications()->latest()->first();
    $this->actingAs($this->guard)->get("/notifications/{$first->id}")->assertRedirect("/incidents/{$incident->id}");
    expect($this->guard->unreadNotifications()->count())->toBe(1);

    $this->actingAs($this->guard)->post('/notifications/read-all')->assertRedirect();
    expect($this->guard->unreadNotifications()->count())->toBe(0);

    $other = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id]);
    $this->actingAs($other)->get("/notifications/{$first->id}")->assertNotFound();
});

it("tells the client's administration when an incident opens in its property", function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => UserRole::ClientAdmin, 'organization_id' => $this->site->organization_id, 'client_id' => $this->client->id]);

    IncidentOpened::dispatch(Incident::factory()->create(['site_id' => $this->site->id]));

    Notification::assertSentTo($manager, IncidentNotice::class);
    Notification::assertSentTo($this->guard, IncidentAlert::class);
});

it('saves the notification preferences', function () {
    $this->actingAs($this->guard)->patch('/settings/notifications', ['email' => false, 'whatsapp' => true, 'push' => true])->assertSessionHasNoErrors();

    expect($this->guard->fresh()->wantsNotificationsBy('email'))->toBeFalse()
        ->and($this->guard->fresh()->wantsNotificationsBy('whatsapp'))->toBeTrue();
});

it('lets the staff message one or several residents, and keeps a record', function () {
    Notification::fake();
    [$a, $b] = User::factory()->count(2)->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);
    $elsewhere = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $this->site->organization_id]);

    $this->actingAs($this->guard)->post("/sites/{$this->site->id}/residents/notify", [
        'resident_ids' => [$a->id, $b->id, $elsewhere->id], 'body' => 'Mañana cortan el agua de 9 a 12.',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo([$a, $b], StaffMessageToResident::class);
    Notification::assertNotSentTo($elsewhere, StaffMessageToResident::class);
    expect(ResidentMessage::sole())->recipients->toBe(2)->sender_id->toBe($this->guard->id);

    $this->actingAs($this->guard)->post("/sites/{$this->site->id}/residents/notify", ['resident_ids' => [], 'body' => 'x'])->assertSessionHasErrors('resident_ids');
});
