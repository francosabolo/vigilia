<?php

namespace App\Services\Cameras;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

class FireVerdict implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'True only if there is visible smoke or flames in the image (not steam from a kettle, fog, dust, a reflection or a red light).')]
    public bool $fire;

    #[Constrained(description: 'Confidence between 0 and 1')]
    public float $confidence;

    #[Constrained(description: 'One sentence in Rioplatense Spanish saying what and where, for the guard. Empty if there is nothing.')]
    public string $description;
}
