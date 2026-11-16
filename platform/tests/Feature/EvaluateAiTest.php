<?php

use Illuminate\Support\Facades\File;

it('explains what is missing before calling the model', function () {
    $dir = 'storage/framework/testing/eval-'.uniqid();
    File::ensureDirectoryExists(base_path("{$dir}/positivos"));

    $this->artisan('seguria:eval', ['path' => $dir])->expectsOutputToContain('No images found')->assertFailed();

    $image = imagecreatetruecolor(10, 10);
    imagejpeg($image, base_path("{$dir}/positivos/a.jpg"));
    config(['services.anthropic.key' => null]);

    $this->artisan('seguria:eval', ['path' => $dir])->expectsOutputToContain('ANTHROPIC_API_KEY')->assertFailed();

    File::deleteDirectory(base_path($dir));
});

it('creates degraded versions of the evaluation set without mixing labels', function () {
    $source = 'storage/framework/testing/eval-src-'.uniqid();
    $target = 'storage/framework/testing/eval-hard-'.uniqid();
    foreach (['positivos', 'negativos'] as $class) {
        File::ensureDirectoryExists(base_path("{$source}/{$class}"));
        $image = imagecreatetruecolor(1280, 720);
        imagejpeg($image, base_path("{$source}/{$class}/a.jpg"));
    }

    $this->artisan('seguria:eval-degrade', ['source' => $source, 'target' => $target])->assertSuccessful();

    foreach (['1_baja_resolucion', '2_muy_baja_resolucion', '3_oscura_con_ruido', '4_desenfoque_niebla'] as $level) {
        expect(File::exists(base_path("{$target}/{$level}/positivos/a.jpg")))->toBeTrue()
            ->and(File::exists(base_path("{$target}/{$level}/negativos/a.jpg")))->toBeTrue()
            ->and(getimagesize(base_path("{$target}/{$level}/positivos/a.jpg"))[0])->toBe(1280);
    }

    File::deleteDirectory(base_path($source));
    File::deleteDirectory(base_path($target));
});
