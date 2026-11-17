<?php

namespace App\Services\Cameras;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

class FramingVerdict implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'Overall: bien, mejorable or mal')]
    public string $rating;

    /** @var array<int, string> */
    #[Constrained(description: 'Up to 4 concrete recommendations in Rioplatense Spanish for the installer (backlight, too far, obstruction, sky too large, IR reflection on glass, blind spot, too high or too low). Empty if it is fine.')]
    public array $recommendations;
}
