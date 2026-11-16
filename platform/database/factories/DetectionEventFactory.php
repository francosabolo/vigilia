<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DetectionEvent>
 */
class DetectionEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'edge_device_id' => EdgeDevice::factory(),
            'camera_id' => Camera::factory(),
            'external_id' => fake()->unique()->uuid(),
            'label' => 'person',
            'score' => 0.82,
            'zones' => [],
            'started_at' => now(),
        ];
    }
}
