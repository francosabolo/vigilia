<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case New = 'new';
    case Notified = 'notified';
    case Acknowledged = 'acknowledged';
    case Escalated = 'escalated';
    case Resolved = 'resolved';

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }
}
