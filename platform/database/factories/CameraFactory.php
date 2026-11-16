<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Camera>
 */
class CameraFactory extends Factory
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
            'edge_device_id' => null,
            'name' => 'Perímetro '.fake()->randomElement(['Norte', 'Sur', 'Este', 'Oeste']),
            'frigate_name' => fake()->unique()->slug(2),
        ];
    }
}
