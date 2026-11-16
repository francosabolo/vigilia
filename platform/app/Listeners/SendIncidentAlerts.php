<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Jobs\EscalateIncident;
use App\Models\User;
use App\Notifications\IncidentAlert;
use App\Services\Incidents\IncidentWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * First line of response: the site's guards (WhatsApp + push), then a timer to escalate to the central.
 */
class SendIncidentAlerts implements ShouldQueue
{
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

        $this->workflow->markNotified($incident, $channels);

        EscalateIncident::dispatch($incident)->delay(now()->addSeconds($site->ack_timeout_seconds));
    }
}
