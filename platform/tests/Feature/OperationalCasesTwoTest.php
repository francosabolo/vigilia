<?php

use App\Enums\UserRole;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Notifications\OwnerAlert;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Cameras\ViewAnalyzer;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

it('alerts the owner of a house with no guard', function () {
    Notification::fake();
    $house = Site::factory()->create(['kind' => 'house']);
    $owner = User::factory()->create(['role' => UserRole::Resident, 'organization_id' => $house->organization_id, 'site_id' => $house->id]);

    IncidentOpened::dispatch(Incident::factory()->create(['site_id' => $house->id]));

    Notification::assertSentTo($owner, OwnerAlert::class);
});

it('does not treat the sun into the lens as a covered camera unless it lasts', function () {
    Storage::fake(config('vigilia.media_disk'));
    $camera = Camera::factory()->create();
    $white = function (): string {
        $image = imagecreatetruecolor(64, 36);
        imagefilledrectangle($image, 0, 0, 63, 35, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image);

        return ob_get_clean();
    };
    $monitor = app(CameraViewMonitor::class);

    expect(app(ViewAnalyzer::class)->compare($white(), null, 90)['glare'])->toBeTrue();
    foreach (range(1, 3) as $check) {
        expect($monitor->check($camera->fresh(), $white()))->toBeNull();
    }
    expect(Incident::count())->toBe(0);

    $this->travel(50)->minutes();
    $monitor->check($camera->fresh(), $white());
    $monitor->check($camera->fresh(), $white());
    expect(Incident::where('title', 'like', 'Cámara tapada%')->count())->toBe(1);
});

it('exports the evidence with a SHA-256 manifest and logs who downloaded it', function () {
    Storage::fake(config('vigilia.media_disk'));
    Storage::disk(config('vigilia.media_disk'))->put('x/snap.jpg', 'imagen');
    $incident = Incident::factory()->create(['snapshot_path' => 'x/snap.jpg']);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $incident->site->organization_id]);

    $response = $this->actingAs($admin)->get("/incidents/{$incident->id}/evidence")->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->getFromName('MANIFIESTO.txt'))->toContain(hash('sha256', 'imagen'))->toContain($admin->name)
        ->and($zip->getFromName('registro.json'))->toContain((string) $incident->id)
        ->and(App\Models\MediaView::where('kind', 'evidence')->count())->toBe(1);
});

it('samples false-alarm closures for the central to check', function () {
    $site = Site::factory()->create();
    Incident::factory()->count(3)->create(['site_id' => $site->id, 'status' => 'resolved', 'resolution' => 'false_alarm', 'resolved_at' => now()->subDay()]);
    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $site->organization_id]);

    $this->artisan('vigilia:sample-dismissed')->assertSuccessful();
    $this->actingAs($operator)->get('/ai/quality')->assertInertia(fn ($page) => $page->has('closures', 3));

    $closure = Incident::whereNotNull('qa_requested_at')->first();
    $this->actingAs($operator)->post("/ai/quality/closures/{$closure->id}", ['correct' => false])->assertRedirect();
    expect($closure->fresh()->qa_result)->toBe('wrong');
});
