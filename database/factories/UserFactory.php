<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => fake()->firstNameFemale(),
            'login' => fake()->unique()->lexify('kadernice-??????'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Staff,
            'color' => '#74624F',
            'is_bookable' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => UserRole::Owner]);
    }
}
