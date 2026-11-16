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

    /**
     * Synthetic night frame that looks like a real IR perimeter camera: grayscale, chain-link fence in
     * perspective with posts and razor wire, trees behind, a street light, sensor noise, vignette and a
     * Hikvision-style OSD. A small "Simulación" mark keeps it honest.
     */
    private function frame(Camera $camera): UploadedFile
    {
        $width = 1280;
        $height = 720;
        mt_srand($camera->id * 7919);
        $image = imagecreatetruecolor($width, $height);
        $gray = fn (int $v) => imagecolorallocate($image, max(0, min(255, $v)), max(0, min(255, $v)), max(0, min(255, $v)));

        // Sky and ground (IR: ground and vegetation reflect, sky stays dark).
        $horizon = 250 + mt_rand(-30, 30);
        for ($y = 0; $y < $height; $y++) {
            $v = $y < $horizon ? 18 + (int) (14 * $y / $horizon) : 70 + (int) (55 * ($y - $horizon) / ($height - $horizon));
            imageline($image, 0, $y, $width, $y, $gray($v));
        }

        // Tree line behind the fence.
        for ($x = -40; $x < $width + 40; $x += mt_rand(40, 90)) {
            $r = mt_rand(45, 110);
            imagefilledellipse($image, $x, $horizon - (int) ($r * 0.3), $r * 2, (int) ($r * 1.6), $gray(mt_rand(42, 62)));
        }

        // Grass texture.
        for ($i = 0; $i < 9000; $i++) {
            $y = mt_rand($horizon, $height - 1);
            $x = mt_rand(0, $width - 1);
            $len = 2 + (int) (6 * ($y - $horizon) / ($height - $horizon));
            imageline($image, $x, $y, $x + mt_rand(-1, 1), $y - $len, $gray(mt_rand(60, 140)));
        }

        // Fence in perspective: near on the left, receding to the right.
        $nearX = -80;
        $farX = $width + 200;
        $nearBase = $height - 140 + mt_rand(-40, 20);
        $farBase = $horizon + 40;
        $nearH = 420;
        $farH = 60;
        $posts = 14;
        $postColor = $gray(150);
        $meshColor = $gray(115);
        $tops = [];
        for ($i = 0; $i <= $posts; $i++) {
            $t = ($i / $posts) ** 1.8;
            $x = (int) ($nearX + ($farX - $nearX) * $t);
            $base = (int) ($nearBase + ($farBase - $nearBase) * $t);
            $h = (int) ($nearH + ($farH - $nearH) * $t);
            $thick = max(1, (int) (9 * (1 - $t)));
            imagefilledrectangle($image, $x, $base - $h, $x + $thick, $base, $postColor);
            $tops[] = [$x, $base - $h, $base, $h];
        }
        for ($i = 0; $i < $posts; $i++) {
            [$x1, $top1, $base1, $h1] = $tops[$i];
            [$x2, $top2, $base2, $h2] = $tops[$i + 1];
            $cells = max(3, (int) (($x2 - $x1) / 9));
            for ($c = 0; $c <= $cells; $c++) {
                $f = $c / $cells;
                $x = (int) ($x1 + ($x2 - $x1) * $f);
                $top = (int) ($top1 + ($top2 - $top1) * $f);
                $base = (int) ($base1 + ($base2 - $base1) * $f);
                imageline($image, $x, $top, (int) ($x + ($x2 - $x1) / $cells * 3), $base, $meshColor);
                imageline($image, $x, $base, (int) ($x + ($x2 - $x1) / $cells * 3), $top, $meshColor);
            }
            imageline($image, $x1, $top1, $x2, $top2, $postColor);
            imageline($image, $x1, $base1, $x2, $base2, $postColor);
            // Razor wire coils on top.
            for ($k = 0; $k < 6; $k++) {
                $cx = (int) ($x1 + ($x2 - $x1) * ($k / 6));
                $cy = (int) ($top1 + ($top2 - $top1) * ($k / 6)) - (int) (0.08 * ($h1 + $h2));
                $size = max(4, (int) (0.12 * ($h1 + $h2) / 2));
                imageellipse($image, $cx, $cy, $size, $size, $gray(170));
            }
        }

        // Street light with glow.
        $lampX = (int) ($width * (0.55 + mt_rand(0, 25) / 100));
        $lampY = $horizon - 120;
        imagefilledrectangle($image, $lampX - 2, $lampY, $lampX + 2, $horizon + 30, $gray(70));
        for ($r = 160; $r > 0; $r -= 4) {
            $v = (int) (26 + 229 * (1 - $r / 160) ** 3);
            imagefilledellipse($image, $lampX, $lampY, $r, (int) ($r * 0.8), $gray($v));
        }

        // Sensor noise (changes every frame) and vignette.
        mt_srand();
        for ($i = 0; $i < 60000; $i++) {
            $x = mt_rand(0, $width - 1);
            $y = mt_rand(0, $height - 1);
            $rgb = imagecolorat($image, $x, $y) & 0xFF;
            imagesetpixel($image, $x, $y, $gray($rgb + mt_rand(-28, 28)));
        }
        for ($r = 0; $r < 140; $r++) {
            imagerectangle($image, $r, $r, $width - 1 - $r, $height - 1 - $r, imagecolorallocatealpha($image, 0, 0, 0, 127 - (int) (14 * (1 - $r / 140) ** 2)));
        }

        // OSD like a Hikvision camera.
        $white = imagecolorallocate($image, 240, 240, 240);
        $black = imagecolorallocate($image, 0, 0, 0);
        $font = collect(['/System/Library/Fonts/Supplemental/Arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'])->first(fn ($path) => is_file($path));
        $stamp = now()->setTimezone('America/Argentina/Buenos_Aires')->locale('es')->isoFormat('DD-MM-YYYY ddd HH:mm:ss');
        $osd = function (string $text, int $x, int $y, int $size) use ($image, $font, $white, $black): void {
            if ($font) {
                foreach ([[-2, 0], [2, 0], [0, -2], [0, 2]] as [$dx, $dy]) {
                    imagettftext($image, $size, 0, $x + $dx, $y + $dy, $black, $font, $text);
                }
                imagettftext($image, $size, 0, $x, $y, $white, $font, $text);
            } else {
                imagestring($image, 5, $x, $y - 15, $text, $white);
            }
        };
        $osd($stamp, 28, 48, 22);
        $osd($camera->name, $width - 40 - (int) (strlen($camera->name) * 13), $height - 34, 20);
        $osd('Simulación', 28, $height - 34, 13);

        $path = tempnam(sys_get_temp_dir(), 'frame').'.jpg';
        imagejpeg($image, $path, 72);
        imagedestroy($image);

        return new UploadedFile($path, 'frame.jpg', 'image/jpeg', null, true);
    }
}
