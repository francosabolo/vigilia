<?php

namespace App\Enums;

enum IncidentResolution: string
{
    case Confirmed = 'confirmed';
    case FalseAlarm = 'false_alarm';
}
