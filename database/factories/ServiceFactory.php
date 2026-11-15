<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => 'Dámský střih',
            'description' => null,
            'price_from' => 550,
            'price_to' => null,
            'work_1' => 45,
            'pause' => 0,
            'work_2' => 0,
            'buffer_after' => 0,
            'is_active' => true,
            'sort' => 0,
        ];
    }

    /** Barvení: práce – působení barvy – práce. */
    public function coloring(int $work1 = 30, int $pause = 40, int $work2 = 60): static
    {
        return $this->state(fn () => [
            'name' => 'Barvení a střih',
            'price_from' => 1400,
            'price_to' => 2200,
            'work_1' => $work1,
            'pause' => $pause,
            'work_2' => $work2,
        ]);
    }
}
