<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Jobs\EscalateIncident;
use App\Models\User;
use App\Notifications\IncidentAlert;
use App\Notifications\IncidentNotice;
use App\Notifications\OwnerAlert;
use App\Services\Incidents\IncidentWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * First line of response: the site's guards (WhatsApp + push), then a timer to escalate to the central.
 */
class SendIncidentAlerts implements ShouldQueue
{
    /** Alerts have their own queue: nothing slow (patrols, reports, AI tests) can delay them. */
    public function viaQueue(): string
    {
        return 'alerts';
    }

    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function handle(IncidentOpened $event): void
    {
        $incident = $event->incident->loadMissing('site');
        $site = $incident->site;

        $guards = User::query()
            ->where('site_id', $site->id)
            ->whereIn('role', [UserRole::Guard, UserRole::SiteAdmin])
            ->get();

        $channels = [];

        if ($guards->isNotEmpty()) {
            Notification::send($guards, new IncidentAlert($incident));
            $channels[] = 'guards';
        }

        if ($site->guard_whatsapp) {
            Notification::route('whatsapp', $site->guard_whatsapp)->notify(new IncidentAlert($incident));
            $channels[] = 'guard_whatsapp';
        }

        // M22 — no guard and no guard phone (a house, a shop): the owner hears it directly; the central takes over
        // through the usual escalation.
        if ($channels === [] && ! $site->kind->hasUnits()) {
            $owners = User::where('site_id', $site->id)->where('role', UserRole::Resident)->get();
            Notification::send($owners, new OwnerAlert($incident->loadMissing('site')));
            if ($owners->isNotEmpty()) {
                $channels[] = 'owner';
            }
        }

        // The client's administration follows its properties: informed, not asked to respond.
        if ($site->client_id) {
            Notification::send(
                User::where('role', UserRole::ClientAdmin)->where('client_id', $site->client_id)->get(),
                new IncidentNotice($incident),
            );
        }

        $this->workflow->markNotified($incident, $channels);

        EscalateIncident::dispatch($incident)->delay(now()->addSeconds($site->ack_timeout_seconds));
    }
}
