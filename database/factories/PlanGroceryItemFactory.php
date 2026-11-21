<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanGroceryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanGroceryItem>
 */
class PlanGroceryItemFactory extends Factory
{
    protected $model = PlanGroceryItem::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'name' => fake()->words(2, true),
            'quantity' => fake()->randomElement([1, 2, 1.5]),
            'unit' => fake()->randomElement(['lb', 'g', 'can', 'bunch']),
            'store_section' => fake()->randomElement(['Produce', 'Meat', 'Dairy', 'Dry goods']),
            'pantry_basic' => false,
            'checked' => false,
            'checked_at' => null,
        ];
    }

    public function checked(): static
    {
        return $this->state([
            'checked' => true,
            'checked_at' => now(),
        ]);
    }

    public function pantryBasic(): static
    {
        return $this->state([
            'pantry_basic' => true,
            'store_section' => 'Pantry',
        ]);
    }
}
