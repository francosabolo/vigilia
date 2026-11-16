<?php

namespace App\Enums;

/**
 * Seguria sells to consorcios: buildings and countries. The interface talks about each in its own words.
 */
enum SiteKind: string
{
    case Building = 'building';
    case Country = 'country';

    public function noun(): string
    {
        return $this === self::Building ? 'edificio' : 'country';
    }

    /**
     * What a resident's home is called: "unidad 4B" in a building, "lote 42" in a country.
     */
    public function unitNoun(): string
    {
        return $this === self::Building ? 'unidad' : 'lote';
    }
}
