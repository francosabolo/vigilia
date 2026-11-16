<?php

namespace App\Enums;

/**
 * How much the system does on its own in a zone. Levels are earned with data, not set by preference.
 */
enum ZoneAutonomy: string
{
    /** New zone: the AI analyzes and records, nobody is alerted. */
    case Calibration = 'calibration';
    /** The AI dismisses the obvious and alerts the guard, who decides (default). */
    case Assisted = 'assisted';
    /** Also triggers the camera's speaker or siren at once on a clear threat, before the guard arrives. */
    case Deterrence = 'deterrence';

    public function label(): string
    {
        return match ($this) {
            self::Calibration => 'Calibración',
            self::Assisted => 'Asistida',
            self::Deterrence => 'Disuasión automática',
        };
    }
}
