<?php

namespace Database\Factories;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'camera_id' => Camera::factory(),
            'severity' => Severity::High,
            'status' => IncidentStatus::New,
            'title' => 'Persona en perímetro',
            'detected_at' => now(),
        ];
    }
}
