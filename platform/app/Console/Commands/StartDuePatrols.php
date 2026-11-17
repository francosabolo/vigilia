<?php

namespace App\Console\Commands;

use App\Services\Patrol\VirtualPatrol;
use Illuminate\Console\Command;

class StartDuePatrols extends Command
{
    protected $signature = 'vigilia:patrol';

    protected $description = 'Start the virtual patrol of every site whose interval has passed';

    public function handle(VirtualPatrol $patrols): int
    {
        foreach ($patrols->dueSites() as $site) {
            $patrols->start($site, 'scheduled');
            $this->line("Ronda iniciada: {$site->name}");
        }

        return self::SUCCESS;
    }
}
