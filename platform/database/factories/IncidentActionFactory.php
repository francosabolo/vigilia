<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentAction>
 */
class IncidentActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'type' => 'note',
        ];
    }
}
