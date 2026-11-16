<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'camera_id' => Camera::factory(),
            'name' => 'Alambrado',
            'frigate_zone' => fake()->unique()->slug(1),
            'is_perimeter' => true,
            'watched_labels' => ['person', 'car'],
        ];
    }
}
