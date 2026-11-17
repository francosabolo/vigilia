<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Administración '.fake()->lastName(),
            'contact_name' => fake()->name(),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => '+54911'.fake()->numerify('########'),
        ];
    }
}
