<?php

namespace App\Enums;

enum ZoneKind: string
{
    case Perimeter = 'perimeter';
    case Door = 'door';
    case Window = 'window';
    case Sensitive = 'sensitive';
    /** J2 — never analyzed, blacked out in every image and masked in Frigate (a street, a neighbour's window). */
    case Privacy = 'privacy';

    public function label(): string
    {
        return match ($this) {
            self::Perimeter => 'perímetro',
            self::Door => 'puerta o portón',
            self::Window => 'ventana',
            self::Sensitive => 'punto sensible',
            self::Privacy => 'privacidad',
        };
    }

    /**
     * A person at a door, window or sensitive point is always critical: it means someone is trying to get in.
     */
    public function isAccessPoint(): bool
    {
        return ! in_array($this, [self::Perimeter, self::Privacy], true);
    }

    /**
     * @return array<int, string>
     */
    public function defaultLabels(): array
    {
        return match (true) {
            $this === self::Privacy => [],
            $this->isAccessPoint() => ['person'],
            default => ['person', 'car', 'motorcycle'],
        };
    }
}
