<?php

namespace Database\Factories;

use App\Models\Recipe;
use App\Models\RecipePrepStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipePrepStep>
 */
class RecipePrepStepFactory extends Factory
{
    protected $model = RecipePrepStep::class;

    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'description' => fake()->sentence(4),
            'lead_time_hours' => fake()->randomElement([0, 2, 24, 48]),
            'position' => 0,
        ];
    }

    /**
     * Prep that happens the night before, like soaking beans.
     */
    public function theNightBefore(): static
    {
        return $this->state(['lead_time_hours' => 24]);
    }

    public function leadTimeHours(int $hours): static
    {
        return $this->state(['lead_time_hours' => $hours]);
    }
}
