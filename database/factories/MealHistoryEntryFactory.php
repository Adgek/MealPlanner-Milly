<?php

namespace Database\Factories;

use App\Models\MealHistoryEntry;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealHistoryEntry>
 */
class MealHistoryEntryFactory extends Factory
{
    protected $model = MealHistoryEntry::class;

    public function definition(): array
    {
        return [
            'cooked_on' => now()->subDays(fake()->numberBetween(1, 60))->toDateString(),
            'recipe_id' => Recipe::factory(),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(['cooked_on' => $date]);
    }

    public function daysAgo(int $days): static
    {
        return $this->state(['cooked_on' => now()->subDays($days)->toDateString()]);
    }
}
