<?php

namespace App\Console\Commands;

use App\Models\EdgeDevice;
use App\Models\Site;
use Illuminate\Console\Command;

class CreateEdgeDevice extends Command
{
    protected $signature = 'seguria:edge-create {site : Site ID} {name : Device name, e.g. edge-norte}';

    protected $description = 'Register an edge box for a site and print its API token (shown only once)';

    public function handle(): int
    {
        $site = Site::find($this->argument('site'));

        if (! $site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $device = new EdgeDevice(['site_id' => $site->id, 'name' => $this->argument('name')]);
        $token = $device->issueToken();

        $this->info("Edge device #{$device->id} created for {$site->name}.");
        $this->line('SEGURIA_TOKEN='.$token);
        $this->warn('Store this token now: it cannot be retrieved again.');

        return self::SUCCESS;
    }
}
