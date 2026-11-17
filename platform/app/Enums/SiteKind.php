<?php

namespace App\Enums;

/**
 * The kinds of property Vigilia watches. The interface talks about each in its own words, and the ones made of
 * houses (country, condominio) place each home on the map.
 */
enum SiteKind: string
{
    case Building = 'building';
    case Condominium = 'condominium';
    case Country = 'country';

    public function noun(): string
    {
        return match ($this) {
            self::Building => 'edificio',
            self::Condominium => 'condominio',
            self::Country => 'country',
        };
    }

    /**
     * What a resident's home is called: "unidad 4B" in a building or a condominio, "lote 42" in a country.
     */
    public function unitNoun(): string
    {
        return $this === self::Country ? 'lote' : 'unidad';
    }

    /**
     * Houses on their own plot can be placed on the map; flats in a tower cannot.
     */
    public function hasHousesOnMap(): bool
    {
        return $this !== self::Building;
    }
}
