<?php

namespace App\Jobs;

use App\Models\Patrol;
use App\Services\Patrol\VirtualPatrol;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunPatrol implements ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $tries = 1;

    public function __construct(public Patrol $patrol)
    {
        $this->onQueue('background');
    }

    public function handle(VirtualPatrol $patrols): void
    {
        $patrols->run($this->patrol);
    }

    public function failed(Throwable $exception): void
    {
        $this->patrol->forceFill(['status' => 'failed', 'summary' => 'La ronda no se pudo completar.', 'finished_at' => now()])->save();
    }
}
