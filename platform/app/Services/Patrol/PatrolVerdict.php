<?php

namespace App\Services\Patrol;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/**
 * What the AI answers for one camera of a virtual patrol.
 */
class PatrolVerdict implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'true only if something in the CURRENT image is out of the ordinary compared with the usual view and deserves a look by the guard')]
    public bool $has_novelty;

    #[Constrained(description: 'One of: persona, vehiculo, porton_abierto, luz_apagada, objeto, dano, fuego_humo, agua, imagen, otro, ninguno')]
    public string $kind;

    #[Constrained(description: 'One of: baja, media, alta. alta = fire, smoke, someone where nobody should be at this hour, a forced or broken gate or fence. media = a gate or door left open, a light off, a vehicle or object that should not be there. baja = minor, worth noting only.')]
    public string $severity;

    #[Constrained(description: 'One short sentence in Rioplatense Spanish for the guard: what is out of place and where in the image. If there is no novelty, say what the camera shows in a few words.')]
    public string $description;

    #[Constrained(description: 'Confidence in has_novelty, from 0 to 1')]
    public float $confidence;
}
