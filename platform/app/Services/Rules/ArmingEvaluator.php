<?php

namespace App\Services\Rules;

use App\Enums\UserRole;
use App\Models\ArmingSchedule;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonInterface;

class ArmingEvaluator
{
    /**
     * A zone covering a resident's lot is armed 24/7 while that resident is in vacation mode.
     * Otherwise a zone without schedules is armed 24/7. Otherwise it is armed when any schedule covers the moment,
     * evaluated in the site's timezone. Overnight windows (22:00 → 06:00) belong to the day they start.
     */
    public function isArmed(Zone $zone, CarbonInterface $at, string $timezone): bool
    {
        if ($zone->lot !== null && $this->ownerIsAway($zone, $at)) {
            return true;
        }

        $schedules = $zone->armingSchedules;

        if ($schedules->isEmpty()) {
            return true;
        }

        $local = $at->copy()->setTimezone($timezone);

        return $schedules->contains(fn (ArmingSchedule $schedule): bool => $this->covers($schedule, $local));
    }

    private function ownerIsAway(Zone $zone, CarbonInterface $at): bool
    {
        return User::query()
            ->where('role', UserRole::Resident)
            ->where('site_id', $zone->camera->site_id)
            ->where('lot', $zone->lot)
            ->where('away_until', '>', $at)
            ->exists();
    }

    private function covers(ArmingSchedule $schedule, CarbonInterface $local): bool
    {
        $days = array_map('intval', $schedule->days ?? []);
        $time = $local->format('H:i:s');
        $start = $this->normalize($schedule->starts_at);
        $end = $this->normalize($schedule->ends_at);

        if ($start <= $end) {
            return in_array($local->isoWeekday(), $days, true) && $time >= $start && $time < $end;
        }

        // Overnight: either the evening part of today, or the early-morning part of a window that started yesterday.
        if ($time >= $start) {
            return in_array($local->isoWeekday(), $days, true);
        }

        return $time < $end && in_array($local->copy()->subDay()->isoWeekday(), $days, true);
    }

    private function normalize(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
