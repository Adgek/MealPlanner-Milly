<?php

namespace Database\Factories;

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Models\BulkIngredient;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'cookable_by' => fake()->randomElement(CookableBy::cases()),
            'effort_tier' => fake()->randomElement(EffortTier::cases()),
            'kid_off_ramp' => fake()->sentence(),
        ];
    }

    /**
     * Something my partner can make on a solo night: quick, and tagged for her.
     */
    public function partnerSolo(): static
    {
        return $this->state([
            'cookable_by' => CookableBy::Partner,
            'effort_tier' => EffortTier::Chaos15,
        ]);
    }

    public function cookableBy(CookableBy $cookableBy): static
    {
        return $this->state(['cookable_by' => $cookableBy]);
    }

    public function effort(EffortTier $tier): static
    {
        return $this->state(['effort_tier' => $tier]);
    }

    /**
     * Attach a bulk ingredient after creating, so the recipe can take part in a
     * raw split (MM-11).
     */
    public function withBulkIngredient(?BulkIngredient $ingredient = null): static
    {
        return $this->afterCreating(function (Recipe $recipe) use ($ingredient) {
            $recipe->bulkIngredients()->attach(
                $ingredient ?? BulkIngredient::factory()->create()
            );
        });
    }

    /**
     * Give it ingredients, so it can appear on a grocery list.
     */
    public function withIngredients(int $count = 3): static
    {
        return $this->afterCreating(function (Recipe $recipe) use ($count) {
            IngredientFactory::new()->count($count)->for($recipe, 'ingredientable')->create();
        });
    }

    /**
     * Give it a prep step that happens the night before.
     */
    public function withPrepStep(string $description = 'Soak the beans', int $leadTimeHours = 24): static
    {
        return $this->afterCreating(function (Recipe $recipe) use ($description, $leadTimeHours) {
            $recipe->prepSteps()->create([
                'description' => $description,
                'lead_time_hours' => $leadTimeHours,
                'position' => 0,
            ]);
        });
    }
}
