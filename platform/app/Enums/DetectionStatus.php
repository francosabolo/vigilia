<?php

namespace App\Enums;

enum DetectionStatus: string
{
    case Pending = 'pending';
    case Threat = 'threat';
    case Dismissed = 'dismissed';
    case Ignored = 'ignored';
}
