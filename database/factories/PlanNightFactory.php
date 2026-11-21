<?php

namespace Database\Factories;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Models\FreezerItem;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanNight>
 */
class PlanNightFactory extends Factory
{
    protected $model = PlanNight::class;

    /**
     * How many nights this process has built.
     *
     * A plan holds one night per date, so a fixed default date would make
     * ->count(3) collide on the unique index. Walking forward a day at a time
     * gives consecutive nights, which is the shape of a real window. Tests that
     * care about the date set it with ->on().
     */
    protected static int $nightsBuilt = 0;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'date' => now()->addDays(1 + static::$nightsBuilt++)->toDateString(),
            'cook' => CookAssignment::Together,
            'time_budget' => EffortTier::Workhorse30,
            'overrides_schedule' => false,
            'override_note' => null,
            // Unplanned by default: the state a night is in before the picker runs.
            'selection' => null,
            'recipe_id' => null,
            'freezer_item_id' => null,
            'is_locked' => false,
        ];
    }

    /**
     * A night with a recipe chosen.
     */
    public function withRecipe(?Recipe $recipe = null): static
    {
        return $this->state(fn () => [
            'selection' => NightSelection::Recipe,
            'recipe_id' => $recipe?->id ?? Recipe::factory(),
            'freezer_item_id' => null,
        ]);
    }

    /**
     * A night pulling a meal out of the freezer.
     */
    public function withFreezerItem(?FreezerItem $item = null): static
    {
        return $this->state(fn () => [
            'selection' => NightSelection::FreezerItem,
            'freezer_item_id' => $item?->id ?? FreezerItem::factory(),
            'recipe_id' => null,
        ]);
    }

    /**
     * Nobody is cooking: travel, leftovers or eating out.
     */
    public function nightOff(string $note = 'Eating out'): static
    {
        return $this->state([
            'selection' => NightSelection::NightOff,
            'recipe_id' => null,
            'freezer_item_id' => null,
            'cook' => CookAssignment::Nobody,
            'overrides_schedule' => true,
            'override_note' => $note,
        ]);
    }

    /**
     * A one-off change to who cooks or how long they have, for this date only.
     */
    public function overridden(CookAssignment $cook, EffortTier $time, string $note): static
    {
        return $this->state([
            'cook' => $cook,
            'time_budget' => $time,
            'overrides_schedule' => true,
            'override_note' => $note,
        ]);
    }

    public function locked(): static
    {
        return $this->state(['is_locked' => true]);
    }

    public function on(string $date): static
    {
        return $this->state(['date' => $date]);
    }
}
