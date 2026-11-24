<?php

namespace Database\Factories;

use App\Models\FreezerItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FreezerItem>
 */
class FreezerItemFactory extends Factory
{
    protected $model = FreezerItem::class;

    public function definition(): array
    {
        return [
            'name' => 'Frozen '.fake()->unique()->words(2, true),
            'portions_left' => fake()->numberBetween(1, 8),
            // Most things want overnight in the fridge.
            'thaw_lead_time_hours' => fake()->randomElement([12, 24]),
            'kid_off_ramp' => fake()->sentence(),
            'retired_at' => null,
        ];
    }

    /**
     * Nothing left — the state MM-12 warns about.
     */
    public function empty(): static
    {
        return $this->state(['portions_left' => 0]);
    }

    public function portions(int $portions): static
    {
        return $this->state(['portions_left' => $portions]);
    }

    /**
     * Taken out of the stash the picker draws from, count and all (MM-15).
     */
    public function retired(): static
    {
        return $this->state(['retired_at' => now()]);
    }
}
