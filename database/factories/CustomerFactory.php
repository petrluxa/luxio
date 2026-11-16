<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => fake()->firstNameFemale().' '.fake()->lastNameFemale(),
            'phone' => '+420'.fake()->unique()->numerify('60#######'),
            'is_verified' => true,
            'consent_at' => now(),
        ];
    }
}
