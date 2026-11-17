<?php

namespace App\Console\Commands;

use App\Services\Residents\SafeArrivals;
use Illuminate\Console\Command;

class CheckSafeArrivals extends Command
{
    protected $signature = 'vigilia:check-arrivals';

    protected $description = 'Warn the guard about residents who said they were arriving and never confirmed';

    public function handle(SafeArrivals $arrivals): int
    {
        $arrivals->checkOverdue();

        return self::SUCCESS;
    }
}
