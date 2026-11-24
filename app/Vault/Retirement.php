<?php

namespace App\Vault;

use App\Models\FreezerItem;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use Illuminate\Support\Facades\DB;

/**
 * Taking a recipe or a freezer meal out of circulation, and leaving the plan
 * coherent afterwards (MM-15).
 *
 * "Warn before deleting" is the floor. What the app actually does is:
 *
 *   - **retire** the meal, so it stays a row and the picker stops choosing it;
 *   - **pick again** for every night that still had it and can still be changed,
 *     so no night is left with a dinner the household has just removed;
 *   - **leave alone** the nights that have gone by (they are the record of what
 *     was eaten, MM-6) and the nights the household locked (a lock is their
 *     answer for that date, and "one last time on Friday" is usually what they
 *     mean);
 *   - **delete the row only when nothing points at it**, which is the one case
 *     where `cascadeOnDelete` on meal history and `nullOnDelete` on the plan
 *     cannot do any damage.
 *
 * Every re-pick goes through MealPlanner, so the date fence, the freezer
 * reservation and the no-repeat rule are MM-6's, not reimplemented here.
 */
final class Retirement
{
    public function __construct(private readonly ?MealPlanner $planner = null) {}

    /**
     * Take a meal out of the vault and repair the plan, returning what was
     * pointing at it before anything changed — which is what the page then tells
     * the household it did.
     */
    public function retire(Recipe|FreezerItem $meal): MealUsage
    {
        $usage = MealUsage::of($meal, $this->planner());

        DB::transaction(function () use ($meal, $usage): void {
            // Retire first: the re-pick below reads the vault, and a meal still in
            // it could be handed straight back to the night it was just taken off.
            $meal->retire();

            $this->repick($usage);
        });

        return $usage;
    }

    /**
     * Put a retired meal back in the vault.
     *
     * Nothing else to undo: retiring never changed a plan night's dinner back,
     * and the stash count was left exactly as it was.
     */
    public function bringBack(Recipe|FreezerItem $meal): bool
    {
        return $meal->bringBack();
    }

    /**
     * Delete the row, when nothing points at it.
     *
     * Refused otherwise, and says so by handing back false rather than throwing:
     * a page that has been open while the plan changed underneath it should show
     * the household why, not a stack trace.
     */
    public function deleteForGood(Recipe|FreezerItem $meal): bool
    {
        $usage = MealUsage::of($meal, $this->planner());

        if (! $usage->canBeDeletedForGood()) {
            return false;
        }

        // Ingredients are polymorphic and are deleted by a model event on the
        // parent, so this has to be a model delete rather than a mass one.
        return (bool) $meal->delete();
    }

    /**
     * Let the planner choose again for the nights that lost their dinner.
     *
     * A re-roll that finds nothing else fitting keeps what the night had and says
     * so ("Kept Chili: nothing else fits.", MM-6). That is deliberate: the shop
     * for that window has already been planned around this meal, so a dinner with
     * an explanation beats an empty night.
     */
    private function repick(MealUsage $usage): void
    {
        $nights = PlanNight::query()
            ->whereKey($usage->nightIdsToRepick())
            ->get();

        foreach ($nights as $night) {
            $this->planner()->rerollNight($night);
        }
    }

    private function planner(): MealPlanner
    {
        return $this->planner ?? new MealPlanner;
    }
}
