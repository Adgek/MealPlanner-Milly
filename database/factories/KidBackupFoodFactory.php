<?php

namespace Database\Factories;

use App\Models\KidBackupFood;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KidBackupFood>
 */
class KidBackupFoodFactory extends Factory
{
    protected $model = KidBackupFood::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'quantity' => fake()->randomElement([1, 2, 3]),
            'unit' => fake()->randomElement(['box', 'bag', 'loaf', 'tub']),
        ];
    }
}
