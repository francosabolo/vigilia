<?php

namespace App\Listeners;

use App\Events\DetectionEventReceived;
use App\Services\Incidents\DetectionProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessDetectionEvent implements ShouldQueue
{
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 15];

    public function __construct(private readonly DetectionProcessor $processor) {}

    public function handle(DetectionEventReceived $event): void
    {
        $this->processor->process($event->detectionEvent->fresh());
    }
}
