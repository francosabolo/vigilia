<?php

namespace App\Simulation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Realistic night-camera images (the generated evaluation set) used to "dress" the demo: each simulated
 * detection gets a picture that matches what really happened, and simulated cameras get a real-looking
 * empty scene as their live view. Images are copied once to the media disk and shared by path.
 */
class DemoImages
{
    /** Scene groups → file name prefixes in the evaluation set. */
    private const GROUPS = [
        'intrusion' => ['positivos' => ['trepando', 'agachados_muro', 'cortando_alambrado', 'escalera_muro', 'escondido_arbustos']],
        'intrusion_door' => ['positivos' => ['forzando_porton', 'ventana']],
        'loitering' => ['positivos' => ['capucha_franja']],
        'animal' => ['negativos' => ['perro', 'gato']],
        'weather' => ['negativos' => ['lluvia', 'viento', 'insectos', 'telarana']],
        'guard_patrol' => ['negativos' => ['guardia_ronda']],
        'vehicle' => ['negativos' => ['faros']],
        // Fixed background of a camera: only scenes that make sense all night long.
        'empty' => ['negativos' => ['viento', 'faros']],
    ];

    /** @var array<string, array<int, string>> */
    private array $files = [];

    public function available(): bool
    {
        return File::isDirectory($this->source().'/positivos') && File::isDirectory($this->source().'/negativos');
    }

    /**
     * Media-disk path of an image for the group (random, or stable for a given seed such as a camera id).
     */
    public function pathFor(string $group, ?int $seed = null): ?string
    {
        $files = $this->filesFor($group);
        if ($files === []) {
            return null;
        }

        $file = $seed === null ? $files[array_rand($files)] : $files[$seed % count($files)];
        $path = 'demo-library/'.basename(dirname($file)).'-'.basename($file);
        $disk = Storage::disk(config('vigilia.media_disk'));
        if (! $disk->exists($path)) {
            $disk->put($path, File::get($file));
        }

        return $path;
    }

    /**
     * Local file of a stable empty scene for a camera (base of its simulated live view).
     */
    public function sceneFile(int $cameraId): ?string
    {
        $files = $this->filesFor('empty');

        return $files === [] ? null : $files[$cameraId % count($files)];
    }

    /**
     * @return array<int, string>
     */
    private function filesFor(string $group): array
    {
        if (! isset(self::GROUPS[$group]) || ! $this->available()) {
            return [];
        }

        return $this->files[$group] ??= collect(self::GROUPS[$group])
            ->flatMap(fn (array $prefixes, string $class) => collect($prefixes)->flatMap(fn ($prefix) => File::glob($this->source()."/{$class}/{$prefix}_*.jpg")))
            ->sort()
            ->values()
            ->all();
    }

    private function source(): string
    {
        return config('vigilia.demo_images_path') ?: storage_path('app/eval');
    }
}
