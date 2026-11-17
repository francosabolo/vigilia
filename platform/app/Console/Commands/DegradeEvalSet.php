<?php

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Makes the evaluation set harder without changing its labels: lower resolution, darkness and sensor noise,
 * blur and fog. A set where everything passes cannot tell two prompts or models apart; this shows where the
 * AI starts to fail (which also tells us the minimum camera quality to recommend).
 *
 * Crops are deliberately not used: a crop can cut the person out and silently turn a positive into a negative.
 */
class DegradeEvalSet extends Command
{
    protected $signature = 'vigilia:eval-degrade
        {source=storage/app/eval : Folder with positivos/ and negativos/}
        {target=storage/app/eval-hard : Output folder, one subfolder per level}';

    protected $description = 'Create degraded versions of the AI evaluation set (resolution, darkness, noise, blur)';

    /** @var array<string, string> level => description */
    private const LEVELS = [
        '1_baja_resolucion' => 'Cámara de 360p o stream con poco ancho de banda',
        '2_muy_baja_resolucion' => 'Cámara barata lejos o substream de 180p, mucha compresión',
        '3_oscura_con_ruido' => 'Noche con poco infrarrojo: oscura y con ruido de sensor',
        '4_desenfoque_niebla' => 'Lente sucio, niebla o humedad: desenfocada y con poco contraste',
    ];

    public function handle(): int
    {
        $source = base_path($this->argument('source'));
        $target = base_path($this->argument('target'));
        $files = File::glob("{$source}/{positivos,negativos}/*.jpg", GLOB_BRACE);

        if ($files === []) {
            $this->error("No images in {$source}/positivos or negativos.");

            return self::FAILURE;
        }

        mt_srand(20261116);
        $noise = $this->noiseTexture();

        foreach (self::LEVELS as $level => $description) {
            $this->line("{$level}: {$description}");
            foreach ($files as $file) {
                $class = basename(dirname($file));
                File::ensureDirectoryExists("{$target}/{$level}/{$class}");
                $image = imagecreatefromjpeg($file);
                [$degraded, $quality] = $this->degrade($image, $level, $noise);
                imagejpeg($degraded, "{$target}/{$level}/{$class}/".basename($file), $quality);
                imagedestroy($degraded);
            }
        }

        $this->info(count($files) * count(self::LEVELS)." images written to {$target}.");
        $this->line('Evaluate each level with: php artisan vigilia:eval '.$this->argument('target').'/<level>');

        return self::SUCCESS;
    }

    /**
     * @return array{0: GdImage, 1: int}
     */
    private function degrade(GdImage $image, string $level, GdImage $noise): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        return match ($level) {
            '1_baja_resolucion' => [$this->rescale($image, 640, 360), 60],
            '2_muy_baja_resolucion' => [$this->rescale($image, 320, 180), 45],
            '3_oscura_con_ruido' => (function () use ($image, $noise, $width, $height) {
                imagefilter($image, IMG_FILTER_BRIGHTNESS, -55);
                imagefilter($image, IMG_FILTER_CONTRAST, 25);
                for ($x = 0; $x < $width; $x += imagesx($noise)) {
                    for ($y = 0; $y < $height; $y += imagesy($noise)) {
                        imagecopymerge($image, $noise, $x, $y, 0, 0, imagesx($noise), imagesy($noise), 22);
                    }
                }

                return [$image, 65];
            })(),
            '4_desenfoque_niebla' => (function () use ($image, $width, $height) {
                for ($i = 0; $i < 6; $i++) {
                    imagefilter($image, IMG_FILTER_GAUSSIAN_BLUR);
                }
                $fog = imagecreatetruecolor($width, $height);
                imagefill($fog, 0, 0, imagecolorallocate($fog, 150, 150, 150));
                imagecopymerge($image, $fog, 0, 0, 0, 0, $width, $height, 35);
                imagedestroy($fog);

                return [$image, 70];
            })(),
        };
    }

    private function rescale(GdImage $image, int $width, int $height): GdImage
    {
        $small = imagecreatetruecolor($width, $height);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        $back = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagecopyresampled($back, $small, 0, 0, 0, 0, imagesx($image), imagesy($image), $width, $height);
        imagedestroy($small);
        imagedestroy($image);

        return $back;
    }

    private function noiseTexture(): GdImage
    {
        $noise = imagecreatetruecolor(256, 256);
        for ($x = 0; $x < 256; $x++) {
            for ($y = 0; $y < 256; $y++) {
                $v = mt_rand(0, 255);
                imagesetpixel($noise, $x, $y, imagecolorallocate($noise, $v, $v, $v));
            }
        }

        return $noise;
    }
}
