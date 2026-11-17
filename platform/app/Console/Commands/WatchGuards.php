<?php

namespace App\Console\Commands;

use App\Services\Guards\GuardWatch;
use Illuminate\Console\Command;

class WatchGuards extends Command
{
    protected $signature = 'vigilia:watch-guards';

    protected $description = 'Check guard posts and round checkpoints where guard supervision is on';

    public function handle(GuardWatch $watch): int
    {
        $watch->run();

        return self::SUCCESS;
    }
}
