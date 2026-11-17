<?php

use App\Enums\CameraViewStatus;
use App\Enums\IncidentSource;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\User;
use App\Services\Cameras\CameraViewMonitor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * A synthetic scene with fence posts; $shiftDeg turns the camera, $covered blacks it out.
 */
function scene(int $shiftDeg = 0, bool $covered = false, int $fov = 90): string
{
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, imagecolorallocate($image, 60, 60, 60));
    mt_srand(42);
    for ($i = 0; $i < 40; $i++) {
        $x = mt_rand(-200, 840);
        imagefilledrectangle($image, $x, mt_rand(60, 180), $x + mt_rand(4, 14), 330, imagecolorallocate($image, 180, 180, 180));
    }
    imagefilledrectangle($image, -200, 200, 840, 210, imagecolorallocate($image, 140, 140, 140));

    if ($shiftDeg !== 0) {
        $turned = imagecreatetruecolor(640, 360);
        imagecopy($turned, $image, -(int) round($shiftDeg / $fov * 640), 0, 0, 0, 640, 360);
        $image = $turned;
    }
    if ($covered) {
        imagefilledrectangle($image, 0, 0, 640, 360, imagecolorallocate($image, 5, 5, 5));
    }

    ob_start();
    imagejpeg($image, null, 85);

    return ob_get_clean();
}

beforeEach(function () {
    Storage::fake('local');
    Event::fake();
    $this->camera = Camera::factory()->create(['fov' => 90, 'heading' => 90]);
    $this->monitor = app(CameraViewMonitor::class);
});

it('learns the reference view and stays quiet while nothing changes', function () {
    expect($this->monitor->check($this->camera, scene()))->toBeNull()
        ->and($this->monitor->check($this->camera, scene()))->toBeNull()
        ->and($this->camera->fresh()->view_status)->toBe(CameraViewStatus::Ok);
});

it('raises a tamper incident after two checks showing the camera turned', function () {
    $this->monitor->check($this->camera, scene());

    expect($this->monitor->check($this->camera, scene(20)))->toBeNull(); // one frame could be a truck passing by

    $incident = $this->monitor->check($this->camera, scene(20));

    expect($incident)->toBeInstanceOf(Incident::class)
        ->and($incident->source)->toBe(IncidentSource::Tamper)
        ->and($incident->title)->toContain('Cámara movida')
        ->and($incident->description)->toContain('derecha')
        ->and($this->camera->fresh()->view_status)->toBe(CameraViewStatus::Moved)
        ->and(abs($this->camera->fresh()->view_shift_deg - 20))->toBeLessThanOrEqual(3);

    // No duplicate incidents while it stays moved.
    expect($this->monitor->check($this->camera, scene(20)))->toBeNull();
});

it('detects a covered camera', function () {
    $this->monitor->check($this->camera, scene());
    $this->monitor->check($this->camera, scene(covered: true));

    expect($this->monitor->check($this->camera, scene(covered: true))->title)->toContain('Cámara tapada');
});

it('lets the central accept the new view and rotate the camera on the map', function () {
    $this->monitor->check($this->camera, scene());
    $this->monitor->check($this->camera, scene(20));
    $incident = $this->monitor->check($this->camera, scene(20));
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->camera->site->organization_id]);
    $guard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => $this->camera->site_id]);

    $this->actingAs($guard)->post("/incidents/{$incident->id}/camera-view", ['apply_shift' => true])->assertForbidden();
    $this->actingAs($operator)->post("/incidents/{$incident->id}/camera-view", ['apply_shift' => true])->assertRedirect();

    $camera = $this->camera->fresh();
    expect($camera->view_status)->toBe(CameraViewStatus::Ok)
        ->and(abs($camera->heading - 110))->toBeLessThanOrEqual(3)
        ->and($this->monitor->check($camera, scene(20)))->toBeNull();
});

it('receives health frames from the edge', function () {
    $device = EdgeDevice::factory()->create(['site_id' => $this->camera->site_id]);
    $this->camera->update(['edge_device_id' => $device->id, 'frigate_name' => 'porton']);
    $token = $device->issueToken();

    $this->withToken($token)->getJson('/api/edge/commands')->assertJsonPath('health_check.cameras', ['porton']);

    $this->withToken($token)->post('/api/edge/health-frames', [
        'camera' => 'porton',
        'image' => UploadedFile::fake()->createWithContent('h.jpg', scene()),
    ], ['Accept' => 'application/json'])->assertOk()->assertJson(['view_status' => 'ok']);

    Storage::disk('local')->assertExists("references/{$this->camera->id}-night.jpg"); // grayscale = IR night view
});

it('warns when the image gets blurry against its reference twice in a row', function () {
    Illuminate\Support\Facades\Storage::fake(config('seguria.media_disk'));
    $camera = App\Models\Camera::factory()->create(['name' => 'Cochera']);
    $sharp = function (): string {
        $image = imagecreatetruecolor(320, 180);
        for ($x = 0; $x < 320; $x += 8) {
            imagefilledrectangle($image, $x, 0, $x + 3, 179, imagecolorallocate($image, 230, 230, 230));
        }
        ob_start();
        imagejpeg($image, null, 95);

        return ob_get_clean();
    };
    $blurry = function () use ($sharp): string {
        $image = imagecreatefromstring($sharp());
        for ($i = 0; $i < 40; $i++) {
            imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
        }
        ob_start();
        imagejpeg($image, null, 95);

        return ob_get_clean();
    };
    $monitor = app(App\Services\Cameras\CameraViewMonitor::class);
    $analyzer = app(App\Services\Cameras\ViewAnalyzer::class);
    expect($analyzer->sharpness($blurry()))->toBeLessThan($analyzer->sharpness($sharp()) * 0.35);

    $reflection = new ReflectionMethod($monitor, 'checkSharpness');
    expect($reflection->invoke($monitor, $camera, $blurry(), $sharp()))->toBeNull();
    $incident = $reflection->invoke($monitor, $camera, $blurry(), $sharp());

    expect($incident->title)->toBe('Imagen borrosa (Cochera)')
        ->and($reflection->invoke($monitor, $camera, $blurry(), $sharp()))->toBeNull();
});
