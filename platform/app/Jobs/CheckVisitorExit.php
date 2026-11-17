<?php

namespace App\Jobs;

use App\Models\AccessEntry;
use App\Services\Visits\VisitWatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * D8 — does the visitor leave with things they did not bring?
 */
class CheckVisitorExit implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public AccessEntry $entry) {}

    public function handle(VisitWatch $watch): void
    {
        $watch->checkExit($this->entry);
    }
}
