<?php

namespace App\Enums;

enum ZoneKind: string
{
    case Perimeter = 'perimeter';
    case Door = 'door';
    case Window = 'window';
    case Sensitive = 'sensitive';

    public function label(): string
    {
        return match ($this) {
            self::Perimeter => 'perímetro',
            self::Door => 'puerta o portón',
            self::Window => 'ventana',
            self::Sensitive => 'punto sensible',
        };
    }

    /**
     * A person at a door, window or sensitive point is always critical: it means someone is trying to get in.
     */
    public function isAccessPoint(): bool
    {
        return $this !== self::Perimeter;
    }

    /**
     * @return array<int, string>
     */
    public function defaultLabels(): array
    {
        return $this->isAccessPoint() ? ['person'] : ['person', 'car', 'motorcycle'];
    }
}
