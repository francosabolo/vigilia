<?php

namespace App\Services\Verification;

class LabelTranslator
{
    private const LABELS = [
        'person' => 'persona',
        'car' => 'auto',
        'motorcycle' => 'moto',
        'bicycle' => 'bicicleta',
        'dog' => 'perro',
        'truck' => 'camión',
    ];

    public static function translate(string $label): string
    {
        return self::LABELS[$label] ?? $label;
    }
}
