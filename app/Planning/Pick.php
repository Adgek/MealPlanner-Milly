<?php

namespace App\Planning;

use App\Enums\NightSelection;
use App\Models\PlanNight;

/**
 * What the picker decided for one night.
 *
 * Carries the night it is for, so a list of these is a whole plan that can be
 * printed or posted without a database behind it — which is what the command
 * does for a dry run, and what the unit tests assert against.
 */
final class Pick
{
    private function __construct(
        public readonly NightBrief $night,
        public readonly ?NightSelection $selection,
        public readonly ?int $recipeId = null,
        public readonly ?int $freezerItemId = null,
        public readonly ?string $mealName = null,
        /**
         * The picker's own note: today, the reason nothing fitted.
         */
        public readonly ?string $note = null,
    ) {}

    public static function recipe(NightBrief $night, RecipeOption $option): self
    {
        return new self(
            night: $night,
            selection: NightSelection::Recipe,
            recipeId: $option->id,
            mealName: $option->name,
        );
    }

    public static function freezerMeal(NightBrief $night, FreezerOption $option): self
    {
        return new self(
            night: $night,
            selection: NightSelection::FreezerItem,
            freezerItemId: $option->id,
            mealName: $option->name,
        );
    }

    /**
     * Nobody is cooking: leftovers, travel or eating out. Not a failure — it is
     * what the household asked for.
     */
    public static function nightOff(NightBrief $night, ?string $note = null): self
    {
        return new self(
            night: $night,
            selection: NightSelection::NightOff,
            note: $note,
        );
    }

    /**
     * The rules ruled everything out. The reason travels with it, and the rest of
     * the plan still goes ahead.
     */
    public static function nothingFits(NightBrief $night, string $reason): self
    {
        return new self(
            night: $night,
            selection: NightSelection::NothingFits,
            note: $reason,
        );
    }

    /**
     * A night nobody has picked for yet.
     */
    public static function unplanned(NightBrief $night): self
    {
        return new self(night: $night, selection: null);
    }

    /**
     * A night as it stands in the database, so a saved plan prints exactly like a
     * freshly rolled one.
     */
    public static function fromPlanNight(PlanNight $night): self
    {
        $brief = new NightBrief(
            date: $night->date,
            cook: $night->cook,
            timeBudget: $night->time_budget,
            overridesSchedule: $night->overrides_schedule,
            note: $night->override_note,
        );

        return new self(
            night: $brief,
            selection: $night->selection,
            recipeId: $night->recipe_id,
            freezerItemId: $night->freezer_item_id,
            mealName: $night->recipe?->name ?? $night->freezerItem?->name,
            note: $night->pick_note,
        );
    }

    public function hasMeal(): bool
    {
        return $this->selection?->hasMeal() ?? false;
    }

    /**
     * The pool's identity for the meal chosen, or null when no meal was.
     */
    public function mealKey(): ?string
    {
        return match (true) {
            $this->recipeId !== null => 'recipe:'.$this->recipeId,
            $this->freezerItemId !== null => 'freezer:'.$this->freezerItemId,
            default => null,
        };
    }

    /**
     * One line for a plan, a Slack post or a console table.
     */
    public function describe(): string
    {
        return match ($this->selection) {
            NightSelection::Recipe => (string) $this->mealName,
            NightSelection::FreezerItem => $this->mealName.' (from the freezer)',
            NightSelection::NightOff => 'Nobody cooking'.($this->night->note ? ' — '.$this->night->note : ''),
            NightSelection::NothingFits => 'Nothing fits',
            null => 'Not planned yet',
        };
    }
}
