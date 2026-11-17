<?php

use App\Models\DetectionEvent;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

it('simulates operation through the real pipeline and reports what it caught', function () {
    Organization::factory()->create(['slug' => 'vigilia']);

    $this->artisan('vigilia:simulate-operation', ['--sites' => 1, '--cameras' => '6-6', '--days' => 2, '--recall' => 1, '--false-pass' => 0])
        ->expectsOutputToContain('Resultado de la simulación')
        ->assertSuccessful();

    $site = Site::where('is_simulated', true)->sole();
    expect($site->cameras()->count())->toBeGreaterThan(0)
        ->and($site->perimeter)->toHaveCount(4)
        ->and(User::where('site_id', $site->id)->where('role', 'resident')->count())->toBeGreaterThan(0)
        ->and(DetectionEvent::count())->toBeGreaterThan(0);

    $this->artisan('vigilia:simulate-operation', ['--reset' => true, '--sites' => 0, '--days' => 0])->assertSuccessful();
    expect(Site::where('is_simulated', true)->count())->toBe(0);
});

it('dresses simulated detections with real-looking images when the demo library exists', function () {
    Storage::fake('local');
    $library = 'storage/framework/testing/demo-'.uniqid();
    foreach (['positivos' => ['trepando_1', 'forzando_porton_1', 'capucha_franja_1'], 'negativos' => ['perro_1', 'lluvia_1', 'guardia_ronda_1', 'faros_1', 'viento_1']] as $class => $names) {
        File::ensureDirectoryExists(base_path("{$library}/{$class}"));
        foreach ($names as $name) {
            imagejpeg(imagecreatetruecolor(16, 9), base_path("{$library}/{$class}/{$name}.jpg"));
        }
    }
    config(['vigilia.demo_images_path' => base_path($library)]);
    Organization::factory()->create(['slug' => 'vigilia']);

    $this->artisan('vigilia:simulate-operation', ['--sites' => 1, '--cameras' => '5-5', '--days' => 1])->assertSuccessful();

    $events = DetectionEvent::all();
    expect($events)->not->toBeEmpty()
        ->and($events->every(fn ($event) => str_starts_with((string) $event->snapshot_path, 'demo-library/')))->toBeTrue();

    File::deleteDirectory(base_path($library));
});
