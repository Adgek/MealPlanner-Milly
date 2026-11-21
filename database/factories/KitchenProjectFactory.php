<?php

namespace Database\Factories;

use App\Models\FreezerItem;
use App\Models\KitchenProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenProject>
 */
class KitchenProjectFactory extends Factory
{
    protected $model = KitchenProject::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'every_days' => fake()->randomElement([7, 14, 21, 30]),
            'produces_freezer_item_id' => null,
            'produces_portions' => null,
            'last_done_on' => now()->subDays(fake()->numberBetween(1, 30))->toDateString(),
        ];
    }

    /**
     * A project that stocks the freezer, like a big batch of soup.
     */
    public function producing(?FreezerItem $item = null, int $portions = 6): static
    {
        return $this->state(fn () => [
            'produces_freezer_item_id' => $item?->id ?? FreezerItem::factory(),
            'produces_portions' => $portions,
        ]);
    }

    /**
     * Never done, so it is due now.
     */
    public function neverDone(): static
    {
        return $this->state(['last_done_on' => null]);
    }

    public function lastDoneOn(string $date): static
    {
        return $this->state(['last_done_on' => $date]);
    }

    public function withIngredients(int $count = 2): static
    {
        return $this->afterCreating(function (KitchenProject $project) use ($count) {
            IngredientFactory::new()->count($count)->for($project, 'ingredientable')->create();
        });
    }
}
