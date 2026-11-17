<?php

namespace App\Services\Visits;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

class VisitorMatch implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'Number of the entry photo (1, 2, 3...) showing the same person as the detection, by clothes, build and what they carry; 0 if none matches or the detection shows no person clearly.')]
    public int $match;

    #[Constrained(description: 'Confidence between 0 and 1')]
    public float $confidence;

    #[Constrained(description: 'One sentence in Rioplatense Spanish: what the person is doing in the detection. Empty if nothing matches.')]
    public string $description;
}
