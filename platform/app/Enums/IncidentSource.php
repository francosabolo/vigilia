<?php

namespace App\Enums;

enum IncidentSource: string
{
    case Detection = 'detection';
    case ResidentReport = 'resident_report';
    case Panic = 'panic';
    case Tamper = 'tamper';
    case Equipment = 'equipment';
}
