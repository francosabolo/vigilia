<?php

namespace App\Listeners;

use App\Events\DetectionEventReceived;
use App\Services\Visits\VisitWatch;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * D6 — with visitors inside, a person in a zone off their route is compared with the entry photos. It runs a few
 * seconds after the detection so it can add to the incident the detection may have opened instead of a second one.
 */
class CheckVisitorRoute implements ShouldQueue
{
    public int $tries = 1;

    public function viaQueue(): string
    {
        return 'alerts';
    }

    public function withDelay(DetectionEventReceived $event): int
    {
        return 15;
    }

    public function __construct(private readonly VisitWatch $watch) {}

    public function handle(DetectionEventReceived $event): void
    {
        $this->watch->checkRoute($event->detectionEvent->fresh());
    }
}
