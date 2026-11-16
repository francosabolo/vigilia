<?php

namespace Database\Factories;

use App\Models\ArmingSchedule;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArmingSchedule>
 */
class ArmingScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'zone_id' => Zone::factory(),
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'starts_at' => '22:00',
            'ends_at' => '06:00',
        ];
    }
}
