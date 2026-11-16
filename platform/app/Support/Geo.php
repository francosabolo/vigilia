<?php

namespace App\Support;

use App\Models\Camera;

class Geo
{
    /**
     * Ground distance between two cameras placed on the map, or null when either has no position.
     */
    public static function distanceMeters(Camera $a, Camera $b): ?int
    {
        if ($a->lat === null || $a->lng === null || $b->lat === null || $b->lng === null) {
            return null;
        }

        $earth = 6_371_000;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * sin($dLng / 2) ** 2;

        return (int) round(2 * $earth * asin(min(1, sqrt($h))));
    }
}
