<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        return [
            // Defaults to belonging to a recipe; use ->for($project, 'ingredientable')
            // for a kitchen project's ingredients.
            'ingredientable_type' => Recipe::class,
            'ingredientable_id' => Recipe::factory(),
            'name' => fake()->words(2, true),
            'quantity' => fake()->randomElement([0.5, 1, 1.5, 2, 400]),
            'unit' => fake()->randomElement(['lb', 'g', 'ml', 'tbsp', 'can', 'bunch']),
            'store_section' => fake()->randomElement(['Produce', 'Meat', 'Dairy', 'Dry goods', 'Frozen']),
            'pantry_basic' => false,
        ];
    }

    /**
     * Something we probably already have — it belongs in the short
     * "check you have" section rather than the main list.
     */
    public function pantryBasic(): static
    {
        return $this->state([
            'pantry_basic' => true,
            'store_section' => 'Pantry',
        ]);
    }

    public function named(string $name, ?float $quantity = null, ?string $unit = null): static
    {
        return $this->state(array_filter([
            'name' => $name,
            'quantity' => $quantity,
            'unit' => $unit,
        ], fn ($value) => $value !== null));
    }
}
