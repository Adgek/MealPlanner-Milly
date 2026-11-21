<?php

namespace Database\Factories;

use App\Models\BulkIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BulkIngredient>
 */
class BulkIngredientFactory extends Factory
{
    protected $model = BulkIngredient::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'fridge_life_days' => fake()->numberBetween(1, 4),
        ];
    }

    /**
     * No fridge life of its own, so the Settings default applies.
     */
    public function withoutFridgeLife(): static
    {
        return $this->state(['fridge_life_days' => null]);
    }

    public function fridgeLifeDays(int $days): static
    {
        return $this->state(['fridge_life_days' => $days]);
    }
}
