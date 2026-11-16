<?php

namespace App\Events;

use App\Models\DetectionEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DetectionEventReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(public DetectionEvent $detectionEvent) {}
}
