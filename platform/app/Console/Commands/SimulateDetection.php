<?php

namespace App\Console\Commands;

use App\Events\DetectionEventReceived;
use App\Models\Camera;
use App\Models\DetectionEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pushes a fake edge detection through the whole pipeline (rules → AI → incident → alerts).
 * Pass --image with a real night capture to exercise the Claude verification.
 */
class SimulateDetection extends Command
{
    protected $signature = 'seguria:simulate
        {camera? : Camera ID (defaults to the first camera)}
        {--label=person : person|car|motorcycle|dog}
        {--zone=alambrado : Frigate zone the object entered}
        {--image= : Path to a JPEG/PNG snapshot}';

    protected $description = 'Simulate an edge detection to demo the full alert pipeline';

    public function handle(): int
    {
        $camera = $this->argument('camera')
            ? Camera::find($this->argument('camera'))
            : Camera::whereNotNull('edge_device_id')->whereHas('zones', fn ($query) => $query->doesntHave('armingSchedules'))->first()
                ?? Camera::whereNotNull('edge_device_id')->first();

        if (! $camera || ! $camera->edge_device_id) {
            $this->error('No camera with an edge device found. Run the seeder or connect an edge first.');

            return self::FAILURE;
        }

        $event = DetectionEvent::create([
            'edge_device_id' => $camera->edge_device_id,
            'camera_id' => $camera->id,
            'external_id' => 'sim-'.Str::uuid(),
            'label' => $this->option('label'),
            'score' => 0.84,
            'zones' => array_filter([$this->option('zone')]),
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        if ($image = $this->option('image')) {
            if (! is_file($image)) {
                $this->error("Image not found: {$image}");

                return self::FAILURE;
            }

            $path = "sites/{$camera->site_id}/events/{$event->id}/snapshot.".strtolower(pathinfo($image, PATHINFO_EXTENSION));
            Storage::disk(config('seguria.media_disk'))->put($path, file_get_contents($image));
            $event->update(['snapshot_path' => $path]);
        }

        DetectionEventReceived::dispatch($event);

        $this->info("Detection #{$event->id} sent for {$camera->name} ({$camera->site->name}).");
        $this->line('Run `php artisan queue:work` (or `composer run dev`) if it is not processed yet.');

        return self::SUCCESS;
    }
}
