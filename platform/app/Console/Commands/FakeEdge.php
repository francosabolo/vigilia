<?php

namespace App\Console\Commands;

use App\Jobs\ReviewReportedSnapshot;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\SnapshotRequest;
use App\Services\Cameras\CameraWall;
use App\Services\Zones\ZoneSync;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Local demo without Frigate: plays the edge's role in-process. Generates synthetic night frames (clearly
 * labelled DEMO) for the camera wall, answers live-frame requests and acknowledges zone syncs.
 */
class FakeEdge extends Command
{
    protected $signature = 'seguria:fake-edge {--once : Run a single cycle and exit}';

    protected $description = 'Simulate edge boxes locally (demo frames for the camera wall, live frames, zone sync)';

    public function handle(CameraWall $wall, ZoneSync $zones): int
    {
        $this->info('Fake edge running. Ctrl+C to stop.');

        do {
            foreach (EdgeDevice::with('cameras')->get() as $device) {
                $device->forceFill([
                    'last_seen_at' => now(),
                    'zones_version' => $zones->payloadFor($device)['version'],
                ])->save();

                if ($wall->isWatched($device->site_id)) {
                    foreach ($device->cameras as $camera) {
                        $wall->storePreview($camera, $this->frame($camera));
                    }
                }

                SnapshotRequest::where('edge_device_id', $device->id)->where('status', 'pending')->with('camera')->get()
                    ->each(function (SnapshotRequest $request): void {
                        $path = "sites/{$request->camera->site_id}/incidents/{$request->incident_id}/live-{$request->camera_id}.jpg";
                        Storage::disk(config('seguria.media_disk'))->put($path, file_get_contents($this->frame($request->camera)->getPathname()));
                        $request->update(['status' => 'fulfilled', 'snapshot_path' => $path, 'sent_at' => now(), 'fulfilled_at' => now()]);
                        ReviewReportedSnapshot::dispatch($request);
                        $this->line("Live frame sent for {$request->camera->name}");
                    });
            }

            if (! $this->option('once')) {
                sleep(CameraWall::PREVIEW_INTERVAL);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function frame(Camera $camera): UploadedFile
    {
        $width = 1280;
        $height = 720;
        $image = imagecreatetruecolor($width, $height);

        // Night sky to ground gradient.
        for ($y = 0; $y < $height; $y++) {
            $shade = (int) (12 + 30 * $y / $height);
            imageline($image, 0, $y, $width, $y, imagecolorallocate($image, $shade, $shade + 8, $shade + 4));
        }

        // Fence line with posts, slightly different per camera.
        $fenceY = 380 + ($camera->id * 37) % 120;
        $wire = imagecolorallocate($image, 110, 120, 110);
        for ($i = 0; $i < 3; $i++) {
            imageline($image, 0, $fenceY + $i * 25, $width, $fenceY - 40 + $i * 25, $wire);
        }
        for ($x = 40; $x < $width; $x += 120) {
            imagefilledrectangle($image, $x, $fenceY - 70, $x + 6, $fenceY + 60, $wire);
        }

        // Moving noise so the wall visibly updates.
        $noise = imagecolorallocate($image, 60, 70, 60);
        for ($i = 0; $i < 400; $i++) {
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $noise);
        }

        $white = imagecolorallocate($image, 230, 230, 230);
        $amber = imagecolorallocate($image, 250, 200, 80);
        imagestring($image, 5, 20, 20, strtoupper($camera->frigate_name), $white);
        imagestring($image, 5, 20, 45, now()->setTimezone('America/Argentina/Buenos_Aires')->format('d/m/Y H:i:s'), $white);
        imagestring($image, 5, $width - 260, 20, 'DEMO - sin Frigate', $amber);

        $path = tempnam(sys_get_temp_dir(), 'frame').'.jpg';
        imagejpeg($image, $path, 70);
        imagedestroy($image);

        return new UploadedFile($path, 'frame.jpg', 'image/jpeg', null, true);
    }
}
