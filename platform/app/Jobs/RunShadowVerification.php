<?php

namespace App\Jobs;

use App\Models\DetectionEvent;
use App\Services\Shadow\ShadowMode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunShadowVerification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $detectionEventId) {}

    public function handle(ShadowMode $shadow): void
    {
        $event = DetectionEvent::with('camera.site')->find($this->detectionEventId);

        if ($event) {
            $shadow->run($event);
        }
    }
}
