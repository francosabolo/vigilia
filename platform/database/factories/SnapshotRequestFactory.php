<?php

namespace Database\Factories;

use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\SnapshotRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SnapshotRequest>
 */
class SnapshotRequestFactory extends Factory
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
            'incident_id' => Incident::factory(),
        ];
    }
}
