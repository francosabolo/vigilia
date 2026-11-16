<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\ResidentSectorNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class NotifyResidentsOfSector implements ShouldQueue
{
    public function handleOpened(IncidentOpened $event): void
    {
        $this->notify($event->incident, closed: false);
    }

    public function handleChanged(IncidentChanged $event): void
    {
        if (in_array($event->change, ['confirmed', 'false_alarm'], true)) {
            $this->notify($event->incident, closed: true);
        }
    }

    private function notify(Incident $incident, bool $closed): void
    {
        if (! $incident->sector) {
            return;
        }

        $residents = User::query()
            ->where('role', UserRole::Resident)
            ->where('site_id', $incident->site_id)
            ->where('sector', $incident->sector)
            ->when($incident->reported_by, fn ($query, int $reporterId) => $query->where('id', '!=', $reporterId))
            ->get();

        Notification::send($residents, new ResidentSectorNotice($incident, $closed));
    }
}
