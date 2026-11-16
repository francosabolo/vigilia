<?php

use App\Models\ArmingSchedule;
use App\Models\Zone;
use App\Services\Rules\ArmingEvaluator;
use Illuminate\Support\Carbon;

const TZ = 'America/Argentina/Buenos_Aires';

function armedAt(Zone $zone, string $localDateTime): bool
{
    return app(ArmingEvaluator::class)->isArmed($zone->fresh('armingSchedules'), Carbon::parse($localDateTime, TZ)->utc(), TZ);
}

it('is always armed when the zone has no schedules', function () {
    expect(armedAt(Zone::factory()->create(), '2026-11-16 14:00'))->toBeTrue();
});

it('handles overnight windows across midnight on the configured days', function () {
    $zone = Zone::factory()->create();
    // Monday 22:00 → Tuesday 06:00 only.
    ArmingSchedule::factory()->for($zone)->create(['days' => [1], 'starts_at' => '22:00', 'ends_at' => '06:00']);

    expect(armedAt($zone, '2026-11-16 23:30'))->toBeTrue()   // Monday night
        ->and(armedAt($zone, '2026-11-17 05:59'))->toBeTrue() // Tuesday early morning, window started Monday
        ->and(armedAt($zone, '2026-11-17 06:00'))->toBeFalse()
        ->and(armedAt($zone, '2026-11-16 21:59'))->toBeFalse()
        ->and(armedAt($zone, '2026-11-16 03:00'))->toBeFalse() // Monday early morning belongs to Sunday's window
        ->and(armedAt($zone, '2026-11-17 23:00'))->toBeFalse(); // Tuesday night not configured
});

it('handles same-day windows', function () {
    $zone = Zone::factory()->create();
    ArmingSchedule::factory()->for($zone)->create(['days' => [6, 7], 'starts_at' => '08:00', 'ends_at' => '20:00']);

    expect(armedAt($zone, '2026-11-21 12:00'))->toBeTrue()   // Saturday
        ->and(armedAt($zone, '2026-11-20 12:00'))->toBeFalse(); // Friday
});
