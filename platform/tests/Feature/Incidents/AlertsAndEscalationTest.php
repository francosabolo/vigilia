<?php

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Jobs\EscalateIncident;
use App\Listeners\SendIncidentAlerts;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Notifications\IncidentAlert;
use App\Services\Incidents\IncidentWorkflow;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->site = Site::factory()->create(['guard_whatsapp' => '+5491155550000', 'ack_timeout_seconds' => 180]);
    $this->incident = Incident::factory()->create(['site_id' => $this->site->id]);
});

it('alerts the site guards and schedules the escalation', function () {
    Notification::fake();
    Queue::fake();
    $guard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => $this->site->id, 'phone' => '+5491100000001']);
    $otherSiteGuard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => Site::factory()->create()->id, 'phone' => '+5491100000002']);

    app(SendIncidentAlerts::class)->handle(new IncidentOpened($this->incident));

    Notification::assertSentTo($guard, IncidentAlert::class);
    Notification::assertNotSentTo($otherSiteGuard, IncidentAlert::class);
    Notification::assertSentOnDemand(IncidentAlert::class, fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['whatsapp'] === '+5491155550000');
    Queue::assertPushed(EscalateIncident::class, fn (EscalateIncident $job) => $job->incident->is($this->incident) && $job->delay !== null);

    expect($this->incident->fresh()->status)->toBe(IncidentStatus::Notified);
});

it('escalates to the central operators when nobody acknowledged', function () {
    Notification::fake();
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->site->organization_id, 'phone' => '+5491100000003']);
    $foreignOperator = User::factory()->create(['role' => UserRole::Operator, 'phone' => '+5491100000004']);

    app()->call([new EscalateIncident($this->incident), 'handle']);

    expect($this->incident->fresh()->status)->toBe(IncidentStatus::Escalated);
    Notification::assertSentTo($operator, IncidentAlert::class, fn (IncidentAlert $alert) => $alert->escalated);
    Notification::assertNotSentTo($foreignOperator, IncidentAlert::class);
});

it('does not escalate an acknowledged incident', function () {
    Notification::fake();
    User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->site->organization_id]);
    app(IncidentWorkflow::class)->acknowledge($this->incident, null, 'web');

    app()->call([new EscalateIncident($this->incident), 'handle']);

    expect($this->incident->fresh()->status)->toBe(IncidentStatus::Acknowledged);
    Notification::assertNothingSent();
});

it('sends the WhatsApp template with action buttons', function () {
    config(['vigilia.whatsapp' => array_merge(config('vigilia.whatsapp'), ['enabled' => true, 'phone_number_id' => '123', 'access_token' => 'tok'])]);
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    Notification::route('whatsapp', '+5491155550000')->notifyNow(new IncidentAlert($this->incident));

    Http::assertSent(function ($request) {
        $template = $request['template'];

        return str_contains($request->url(), '/123/messages')
            && $request['to'] === '5491155550000'
            && $template['name'] === 'alerta_intrusion'
            && collect($template['components'])->where('type', 'button')->pluck('parameters.0.payload')->all() === ["ack:{$this->incident->id}", "false:{$this->incident->id}"];
    });
});
