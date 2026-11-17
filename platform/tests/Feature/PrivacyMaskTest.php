<?php

use App\Events\DetectionEventReceived;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Services\Zones\ZoneSync;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('vigilia.media_disk'));
    Event::fake([DetectionEventReceived::class]);
    $this->device = EdgeDevice::factory()->create();
    $this->token = $this->device->issueToken();
    $this->camera = Camera::factory()->create(['site_id' => $this->device->site_id, 'edge_device_id' => $this->device->id, 'frigate_name' => 'frente']);
    // The left half of the image is the street.
    $this->camera->zones()->create(['name' => 'Calle', 'frigate_zone' => 'calle', 'kind' => 'privacy', 'watched_labels' => [], 'coordinates' => [[0, 0], [0.5, 0], [0.5, 1], [0, 1]]]);
    $this->camera->zones()->create(['name' => 'Portón', 'frigate_zone' => 'porton', 'kind' => 'door', 'watched_labels' => ['person'], 'coordinates' => [[0.5, 0], [1, 0], [1, 1], [0.5, 1]]]);
});

function whiteJpeg(): UploadedFile
{
    $image = imagecreatetruecolor(100, 50);
    imagefilledrectangle($image, 0, 0, 99, 49, imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagejpeg($image);

    return UploadedFile::fake()->createWithContent('snapshot.jpg', ob_get_clean());
}

it('blacks out the privacy areas before storing the image', function () {
    $this->withToken($this->token)->post('/api/edge/events', [
        'id' => 'e-1', 'camera' => 'frente', 'label' => 'person', 'start_time' => now()->timestamp, 'snapshot' => whiteJpeg(),
    ])->assertCreated();

    $stored = imagecreatefromstring(Storage::disk(config('vigilia.media_disk'))->get(DetectionEvent::sole()->snapshot_path));
    $left = imagecolorsforindex($stored, imagecolorat($stored, 10, 25));
    $right = imagecolorsforindex($stored, imagecolorat($stored, 90, 25));

    expect($left['red'])->toBeLessThan(20)->and($right['red'])->toBeGreaterThan(230);
});

it('sends privacy areas to the edge as masks, not as zones', function () {
    $payload = app(ZoneSync::class)->payloadFor($this->device);

    expect($payload['cameras']['frente'])->toHaveKey('porton')->not->toHaveKey('calle')
        ->and($payload['masks']['frente'])->toBe(['0,0,0.5,0,0.5,1,0,1']);
});
