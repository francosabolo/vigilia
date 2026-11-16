<?php

namespace App\Services\Cameras;

/**
 * Compares a camera frame with its reference view, with plain image math (no AI, no per-call cost).
 *
 * - Covered / blinded: the frame is almost uniform (hand, tape, spray paint, a light pointed at the lens).
 * - Moved: the edge map correlates well with the reference only after a horizontal/vertical shift. The
 *   horizontal shift over the image width, times the field of view, estimates how much it was turned.
 * - Changed: nothing correlates (turned a lot, or the scene is different).
 */
class ViewAnalyzer
{
    private const W = 64;

    private const H = 36;

    private const MAX_DX = 20;

    private const MAX_DY = 6;

    /**
     * @return array{status: 'ok'|'moved'|'covered', similarity: float, shift_x: ?int, shift_deg: ?int, bucket: 'day'|'night'}
     */
    public function compare(string $frameBytes, ?string $referenceBytes, int $fovDeg): array
    {
        [$gray, $saturation] = $this->downscale($frameBytes);
        $bucket = $saturation < 0.06 ? 'night' : 'day';

        $mean = array_sum($gray) / count($gray);
        $std = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $gray)) / count($gray));

        if ($std < 6 || $mean < 6 || $mean > 248) {
            return ['status' => 'covered', 'similarity' => 0.0, 'shift_x' => null, 'shift_deg' => null, 'bucket' => $bucket];
        }

        if ($referenceBytes === null) {
            return ['status' => 'ok', 'similarity' => 1.0, 'shift_x' => null, 'shift_deg' => null, 'bucket' => $bucket];
        }

        $frameEdges = $this->edges($gray);
        $referenceEdges = $this->edges($this->downscale($referenceBytes)[0]);

        $atZero = $this->correlation($referenceEdges, $frameEdges, 0, 0);
        $best = ['corr' => $atZero, 'dx' => 0, 'dy' => 0];

        for ($dy = -self::MAX_DY; $dy <= self::MAX_DY; $dy++) {
            for ($dx = -self::MAX_DX; $dx <= self::MAX_DX; $dx++) {
                $corr = $this->correlation($referenceEdges, $frameEdges, $dx, $dy);
                if ($corr > $best['corr'] + 0.01) {
                    $best = ['corr' => $corr, 'dx' => $dx, 'dy' => $dy];
                }
            }
        }

        if ($atZero >= 0.55) {
            return ['status' => 'ok', 'similarity' => round($atZero, 3), 'shift_x' => 0, 'shift_deg' => 0, 'bucket' => $bucket];
        }

        $shiftDeg = $best['corr'] >= 0.5 && abs($best['dx']) >= 2 ? (int) round(-$best['dx'] / self::W * $fovDeg) : null;

        return ['status' => 'moved', 'similarity' => round($atZero, 3), 'shift_x' => $best['dx'], 'shift_deg' => $shiftDeg, 'bucket' => $bucket];
    }

    /**
     * @return array{0: array<int, float>, 1: float} grayscale pixels and mean saturation
     */
    private function downscale(string $bytes): array
    {
        $source = imagecreatefromstring($bytes);
        $small = imagecreatetruecolor(self::W, self::H);
        imagecopyresampled($small, $source, 0, 0, 0, 0, self::W, self::H, imagesx($source), imagesy($source));

        $gray = [];
        $saturation = 0.0;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                [$r, $g, $b] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                $gray[] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $max = max($r, $g, $b);
                $saturation += $max === 0 ? 0 : ($max - min($r, $g, $b)) / $max;
            }
        }

        return [$gray, $saturation / (self::W * self::H)];
    }

    /**
     * Gradient magnitude: robust to brightness changes between frames.
     *
     * @param  array<int, float>  $gray
     * @return array<int, float>
     */
    private function edges(array $gray): array
    {
        $edges = [];
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $gx = $gray[$y * self::W + min($x + 1, self::W - 1)] - $gray[$y * self::W + max($x - 1, 0)];
                $gy = $gray[min($y + 1, self::H - 1) * self::W + $x] - $gray[max($y - 1, 0) * self::W + $x];
                $edges[] = sqrt($gx * $gx + $gy * $gy);
            }
        }

        return $edges;
    }

    /**
     * Normalized cross-correlation of the overlapping region when the frame is shifted by (dx, dy).
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function correlation(array $a, array $b, int $dx, int $dy): float
    {
        $pairs = [];
        for ($y = max(0, -$dy); $y < min(self::H, self::H - $dy); $y++) {
            for ($x = max(0, -$dx); $x < min(self::W, self::W - $dx); $x++) {
                $pairs[] = [$a[$y * self::W + $x], $b[($y + $dy) * self::W + $x + $dx]];
            }
        }

        $n = count($pairs);
        if ($n < self::W * self::H / 3) {
            return -1.0;
        }

        $meanA = array_sum(array_column($pairs, 0)) / $n;
        $meanB = array_sum(array_column($pairs, 1)) / $n;
        $num = $denA = $denB = 0.0;
        foreach ($pairs as [$va, $vb]) {
            $num += ($va - $meanA) * ($vb - $meanB);
            $denA += ($va - $meanA) ** 2;
            $denB += ($vb - $meanB) ** 2;
        }

        return $denA > 0 && $denB > 0 ? $num / sqrt($denA * $denB) : 0.0;
    }
}
