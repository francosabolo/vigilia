<?php

namespace App\Enums;

/**
 * The kinds of property Vigilia watches. The interface talks about each in its own words; the ones made of houses
 * (country, condominio) place each home on the map, and single properties have no units at all.
 */
enum SiteKind: string
{
    case Building = 'building';
    case Condominium = 'condominium';
    case Country = 'country';
    case House = 'house';
    case Apartment = 'apartment';
    case Store = 'store';
    case Warehouse = 'warehouse';

    public function noun(): string
    {
        return match ($this) {
            self::Building => 'edificio',
            self::Condominium => 'condominio',
            self::Country => 'country',
            self::House => 'casa',
            self::Apartment => 'departamento',
            self::Store => 'local',
            self::Warehouse => 'nave',
        };
    }

    /**
     * What a resident's home is called: "lote 42" in a country, "unidad 4B" everywhere else.
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
        return in_array($this, [self::Condominium, self::Country], true);
    }

    /**
     * Several homes with their own residents (consorcios, barrios). A house, a flat, a shop or a warehouse is one.
     */
    public function hasUnits(): bool
    {
        return in_array($this, [self::Building, self::Condominium, self::Country], true);
    }
}
