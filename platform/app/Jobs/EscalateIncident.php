<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\IncidentAlert;
use App\Services\Incidents\IncidentWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Runs after the site's ack timeout: if no guard acknowledged, the central operators take over.
 */
class EscalateIncident implements ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident) {}

    public function handle(IncidentWorkflow $workflow): void
    {
        $incident = $this->incident->fresh('site');

        if (! $incident || ! $workflow->escalate($incident)) {
            return;
        }

        $operators = User::query()
            ->where('organization_id', $incident->site->organization_id)
            ->whereIn('role', [UserRole::Operator, UserRole::Admin])
            ->get();

        Notification::send($operators, new IncidentAlert($incident, escalated: true));
    }
}
