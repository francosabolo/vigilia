<?php

namespace App\Services\Guards;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

class GuardPostVerdict implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'true if at least one person is at the guard post (sitting, standing or walking inside it)')]
    public bool $person_present;

    #[Constrained(description: 'One short sentence in Rioplatense Spanish describing who is at the post, or that it is empty.')]
    public string $description;
}
