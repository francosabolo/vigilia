<?php

namespace App\Console\Commands;

use App\Services\Visits\VisitWatch;
use Illuminate\Console\Command;

class WatchVisits extends Command
{
    protected $signature = 'vigilia:watch-visits';

    protected $description = 'D7 — tell the guard about visits and providers still inside after their time';

    public function handle(VisitWatch $watch): int
    {
        $this->info($watch->notifyOverdue().' visitas vencidas avisadas.');

        return self::SUCCESS;
    }
}
