<?php

namespace App\Services\Visits;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

class ExitCheck implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'True only if the person leaves carrying bags, boxes, tools, appliances or other objects they clearly did not have when entering.')]
    public bool $carries_more;

    #[Constrained(description: 'What they carry now that they did not before, in Rioplatense Spanish, short (e.g. "una caja grande y una bolsa negra"). Empty if nothing.')]
    public string $description;
}
