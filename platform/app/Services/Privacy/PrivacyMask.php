<?php

namespace App\Services\Privacy;

use App\Enums\ZoneKind;
use App\Models\Camera;
use Throwable;

/**
 * J2 — blacks out the privacy areas of a camera (the street, a neighbour's window) in every image before it is
 * stored or sent to the AI. The edge masks them in Frigate too; this is the guarantee on the platform side.
 */
class PrivacyMask
{
    public function apply(Camera $camera, string $bytes): string
    {
        $polygons = $camera->zones()->where('kind', ZoneKind::Privacy)->get()
            ->pluck('coordinates')
            ->filter(fn ($points) => is_array($points) && count($points) >= 3)
            ->values();

        if ($polygons->isEmpty()) {
            return $bytes;
        }

        try {
            $image = imagecreatefromstring($bytes);
        } catch (Throwable) {
            return $bytes;
        }
        if ($image === false) {
            return $bytes;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $black = imagecolorallocate($image, 0, 0, 0);

        foreach ($polygons as $points) {
            $flat = [];
            foreach ($points as [$x, $y]) {
                $flat[] = (int) round($x * $width);
                $flat[] = (int) round($y * $height);
            }
            imagefilledpolygon($image, $flat, $black);
        }

        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
