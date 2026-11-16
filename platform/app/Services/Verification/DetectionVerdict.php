<?php

namespace App\Services\Verification;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/**
 * Structured output schema the vision model must answer with.
 */
class DetectionVerdict implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'true if the image shows a real intrusion risk: an unauthorized person or vehicle in or crossing the perimeter')]
    public bool $is_threat;

    #[Constrained(description: 'Confidence in the is_threat verdict, from 0 to 1')]
    public float $confidence;

    #[Constrained(description: 'One or two sentences in Rioplatense Spanish describing what is happening, for a security guard. Mention count, position and behavior.')]
    public string $description;

    #[Constrained(description: 'Number of people or vehicles relevant to the alert')]
    public int $object_count;

    #[Constrained(description: 'true if the person looks like a uniformed security guard doing a patrol')]
    public bool $suspected_guard;
}
