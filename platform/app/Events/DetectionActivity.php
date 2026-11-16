<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live feed of what the system is doing with a detection ("analyzing" first, then the decision).
 * Broadcast immediately (not queued) so the two states arrive in order.
 */
class DetectionActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $item
     */
    public function __construct(public int $organizationId, public int $siteId, public array $item) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("operators.{$this->organizationId}"), new PrivateChannel("sites.{$this->siteId}")];
    }

    public function broadcastAs(): string
    {
        return 'detection.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['item' => $this->item];
    }
}
