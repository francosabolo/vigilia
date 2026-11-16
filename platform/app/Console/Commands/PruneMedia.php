<?php

namespace App\Console\Commands;

use App\Services\Media\MediaRetention;
use Illuminate\Console\Command;

class PruneMedia extends Command
{
    protected $signature = 'seguria:prune-media';

    protected $description = 'Delete images and clips older than the retention window (keeps evidence of open and confirmed incidents)';

    public function handle(MediaRetention $retention): int
    {
        $stats = $retention->prune();
        $this->line(sprintf('Deleted %d files: %d detections, %d incidents, %d live frames (retention %d days).',
            $stats['files'], $stats['detections'], $stats['incidents'], $stats['live_frames'], config('seguria.retention_days')));

        return self::SUCCESS;
    }
}
