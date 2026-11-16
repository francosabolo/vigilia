<?php

namespace App\Simulation;

/**
 * What a simulated event really was. Only the simulator knows it; the pipeline never sees it.
 */
enum Truth: string
{
    case Intrusion = 'intrusion';
    case Loitering = 'loitering';
    case Animal = 'animal';
    case Weather = 'weather';
    case GuardPatrol = 'guard_patrol';
    case Resident = 'resident';
    case Vehicle = 'vehicle';

    public function isThreat(): bool
    {
        return $this === self::Intrusion;
    }

    /**
     * Whether a good verifier should alert on it (ground truth for shadow mode): intrusions, and someone
     * loitering in an armed zone at night, which is what the loitering images show.
     */
    public function shouldAlert(): bool
    {
        return in_array($this, [self::Intrusion, self::Loitering], true);
    }
}
