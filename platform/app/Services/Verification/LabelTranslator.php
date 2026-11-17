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
        'motion' => 'movimiento sin persona detectada',
    ];

    private const MASCULINE = ['car', 'dog', 'truck'];

    public static function translate(string $label): string
    {
        return self::LABELS[$label] ?? $label;
    }

    /**
     * "la persona" / "el auto"; with $indefinite, "una persona" / "un auto".
     */
    public static function withArticle(string $label, bool $indefinite = false): string
    {
        $masculine = in_array($label, self::MASCULINE, true);
        $article = $indefinite ? ($masculine ? 'un' : 'una') : ($masculine ? 'el' : 'la');

        return $article.' '.self::translate($label);
    }
}
