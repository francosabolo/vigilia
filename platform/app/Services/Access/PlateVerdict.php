<?php

namespace App\Services\Access;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

class PlateVerdict implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'true if a car, van, truck or motorcycle is at the gate')]
    public bool $vehicle_present;

    #[Constrained(description: 'The license plate exactly as read, uppercase without spaces (Argentine formats like AB123CD or ABC123), or an empty string if it cannot be read with certainty')]
    public string $plate;

    #[Constrained(description: 'Short description of the vehicle in Rioplatense Spanish (type and color), or empty')]
    public string $vehicle;
}
