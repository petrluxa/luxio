<?php

namespace Database\Factories;

use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Salon>
 */
class SalonFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Kadeřnictví '.fake()->unique()->firstNameFemale();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'phone' => '+420603'.fake()->numerify('######'),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'timezone' => 'Europe/Prague',
            'settings' => null,
        ];
    }
}
