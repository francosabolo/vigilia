<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to the operators console and to the site's guards whenever an incident is created or changes state.
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
        return [
            new PrivateChannel('operators.'.$this->incident->site->organization_id),
            new PrivateChannel('sites.'.$this->incident->site_id),
        ];
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
                'status' => $this->incident->status->value,
                'severity' => $this->incident->severity->value,
                'title' => $this->incident->title,
            ],
        ];
    }
}
