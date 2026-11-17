<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the operators console, the client's administration and the site's guards whenever an incident is created or changes state.
 */
class IncidentChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Incident $incident, public string $change) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $site = $this->incident->site;

        return array_values(array_filter([
            new PrivateChannel('operators.'.$site->organization_id),
            new PrivateChannel('sites.'.$this->incident->site_id),
            $site->client_id ? new PrivateChannel('clients.'.$site->client_id) : null,
        ]));
    }

    /**
     * The screen must not wait behind long jobs (patrols, reports): incident updates go with the alerts.
     */
    public function broadcastQueue(): string
    {
        return 'alerts';
    }

    public function broadcastAs(): string
    {
        return 'incident.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'change' => $this->change,
            'incident' => [
                'id' => $this->incident->id,
                'site_id' => $this->incident->site_id,
                'site' => $this->incident->site->name,
                'status' => $this->incident->status->value,
                'severity' => $this->incident->severity->value,
                'title' => $this->incident->title,
            ],
        ];
    }
}
