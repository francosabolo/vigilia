<?php

namespace App\Console\Commands;

use App\Services\Incidents\EquipmentWatch;
use Illuminate\Console\Command;

class WatchEquipment extends Command
{
    protected $signature = 'vigilia:watch-equipment';

    protected $description = 'Raise incidents for edge boxes without signal and cameras without image (and close them on recovery)';

    public function handle(EquipmentWatch $watch): int
    {
        $result = $watch->run();
        $this->line("Opened {$result['opened']}, recovered {$result['recovered']}.");

        return self::SUCCESS;
    }
}
