<?php

namespace App\Enums;

enum ZoneKind: string
{
    case Perimeter = 'perimeter';
    case Door = 'door';
    case Window = 'window';
    case Sensitive = 'sensitive';
    /** M11 — parking: someone crouching by cars or walking among them looking. */
    case Garage = 'garage';
    /** M12/M14/M16 — hall, landing, stairs: sleeping, lying on the floor, fighting. */
    case CommonArea = 'common_area';
    /** M15 — the pool: a child alone, people out of hours. */
    case Pool = 'pool';
    /** M9 — a building site: nobody out of working hours, materials being carried away. */
    case Construction = 'construction';
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
            self::Garage => 'cochera',
            self::CommonArea => 'espacio común',
            self::Pool => 'pileta',
            self::Construction => 'obra',
        };
    }

    /**
     * A person at a door, window or sensitive point is always critical: it means someone is trying to get in.
     */
    public function isAccessPoint(): bool
    {
        return in_array($this, [self::Door, self::Window, self::Sensitive], true);
    }

    /**
     * @return array<int, string>
     */
    public function defaultLabels(): array
    {
        return match (true) {
            $this === self::Privacy => [],
            $this === self::Garage => ['person', 'car', 'motorcycle'],
            in_array($this, [self::CommonArea, self::Pool, self::Construction], true) => ['person'],
            $this->isAccessPoint() => ['person'],
            default => ['person', 'car', 'motorcycle'],
        };
    }

    /**
     * What the AI must watch for in this kind of zone, sent with every detection there. Context, not the prompt,
     * so the evaluated prompt and its cached verdicts do not change.
     */
    public function aiHint(): ?string
    {
        return match ($this) {
            self::Garage => 'Es una cochera: es amenaza si alguien se agacha junto a autos, prueba puertas, saca ruedas o espejos, o recorre la cochera mirando autos sin subirse a uno.',
            self::CommonArea => 'Es un espacio común (hall, palier o escalera): también es alerta si alguien está acostado o tirado en el piso (puede ser una emergencia médica o una persona durmiendo ahí) o si hay una pelea o agresión.',
            self::Pool => 'Es la pileta: es alerta si hay un chico sin un adulto cerca, alguien en el agua fuera del horario de la pileta o alguien que parece tener problemas en el agua.',
            self::Construction => 'Es una obra en construcción: fuera del horario de obra no debería haber nadie; es amenaza si alguien carga materiales, herramientas o caños hacia afuera.',
            default => null,
        };
    }
}
