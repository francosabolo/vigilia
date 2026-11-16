<?php

namespace Database\Factories;

use App\Models\EdgeDevice;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EdgeDevice>
 */
class EdgeDeviceFactory extends Factory
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
            'name' => 'edge-'.fake()->unique()->numberBetween(1, 9999),
            'token_hash' => hash('sha256', Str::random(40)),
        ];
    }
}
