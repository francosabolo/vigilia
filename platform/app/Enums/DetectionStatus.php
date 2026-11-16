<?php

namespace App\Enums;

enum DetectionStatus: string
{
    case Pending = 'pending';
    case Threat = 'threat';
    case Dismissed = 'dismissed';
    case Ignored = 'ignored';
    /** Verified while the zone is in calibration: recorded, nobody alerted. */
    case Observed = 'observed';
}
