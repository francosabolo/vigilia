<?php

use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Models\DetectionEvent;
use App\Models\Incident;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['vigilia.media_disk' => 'local', 'vigilia.retention_days' => 30]);
    Storage::fake('local');
});

function storedDetection(string $name, array $attributes = []): DetectionEvent
{
    Storage::disk('local')->put("sites/1/{$name}.jpg", 'jpg');

    return DetectionEvent::factory()->create(['snapshot_path' => "sites/1/{$name}.jpg"] + $attributes);
}

it('deletes old images but keeps recent ones, open and confirmed evidence and shared demo images', function () {
    $old = now()->subDays(40);
    $falseAlarm = Incident::factory()->create(['status' => IncidentStatus::Resolved, 'resolution' => IncidentResolution::FalseAlarm, 'detected_at' => $old]);
    $confirmed = Incident::factory()->create(['status' => IncidentStatus::Resolved, 'resolution' => IncidentResolution::Confirmed, 'detected_at' => $old]);
    $open = Incident::factory()->create(['status' => IncidentStatus::Acknowledged, 'detected_at' => $old]);

    $expired = storedDetection('expired', ['created_at' => $old]);
    $expiredFalseAlarm = storedDetection('false-alarm', ['created_at' => $old, 'incident_id' => $falseAlarm->id]);
    $evidence = storedDetection('evidence', ['created_at' => $old, 'incident_id' => $confirmed->id]);
    $ongoing = storedDetection('ongoing', ['created_at' => $old, 'incident_id' => $open->id]);
    $recent = storedDetection('recent', ['created_at' => now()->subDays(2)]);
    $demo = DetectionEvent::factory()->create(['snapshot_path' => 'demo-library/positivos-trepando_1.jpg', 'created_at' => $old]);
    Storage::disk('local')->put('demo-library/positivos-trepando_1.jpg', 'jpg');

    $this->artisan('vigilia:prune-media')->assertSuccessful();

    Storage::disk('local')->assertMissing(['sites/1/expired.jpg', 'sites/1/false-alarm.jpg']);
    Storage::disk('local')->assertExists(['sites/1/evidence.jpg', 'sites/1/ongoing.jpg', 'sites/1/recent.jpg', 'demo-library/positivos-trepando_1.jpg']);
    expect($expired->fresh()->snapshot_path)->toBeNull()
        ->and($expiredFalseAlarm->fresh()->snapshot_path)->toBeNull()
        ->and($evidence->fresh()->snapshot_path)->not->toBeNull()
        ->and($demo->fresh()->snapshot_path)->toBeNull();
});
