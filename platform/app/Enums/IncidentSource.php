<?php

namespace App\Enums;

enum IncidentSource: string
{
    case Detection = 'detection';
    case ResidentReport = 'resident_report';
    case Panic = 'panic';
    case Tamper = 'tamper';
    case Equipment = 'equipment';
    /** Something the virtual patrol found out of place (gate open, light off, someone where nobody should be). */
    case Patrol = 'patrol';
}
