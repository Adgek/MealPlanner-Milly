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
     * Take a meal out of the vault and repair the plan, reporting what really
     * happened — which is what the page then tells the household it did.
     *
     * The report is built from the outcome rather than from the intention. A
     * re-pick that finds nothing else fitting keeps the meal on the night (MM-6),
     * so "these nights will be picked again" is a forecast that is sometimes
     * wrong, and a banner written from it claimed a dinner had changed while the
     * plan still read the same.
     */
    public function retire(Recipe|FreezerItem $meal): Removal
    {
        $usage = MealUsage::of($meal, $this->planner());
        $portionsBefore = $meal instanceof FreezerItem ? $meal->portions_left : 0;

        $outcome = DB::transaction(function () use ($meal, $usage): array {
            // Retire first: the re-pick below reads the vault, and a meal still in
            // it could be handed straight back to the night it was just taken off.
            $meal->retire();

            return $this->repick($usage);
        });

        $portionsAfter = $meal instanceof FreezerItem
            ? ($meal->fresh()?->portions_left ?? $portionsBefore)
            : 0;

        return new Removal(
            name: $usage->name,
            fromTheStash: $meal instanceof FreezerItem,
            changed: $outcome['changed'],
            kept: $outcome['kept'],
            locked: $usage->lockedNights(),
            portionsHandedBack: max(0, $portionsAfter - $portionsBefore),
            hadBeenEaten: $usage->hasBeenEaten(),
        );
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
     *
     * The check and the delete are one transaction, with the row locked for the
     * length of it. Without that they are two statements with a gap in between:
     * a plan being made in the scheduler could claim the recipe after the check
     * said nothing pointed at it, and the delete would then null that night out.
     * The lock also makes the ingredient cascade part of the same unit of work,
     * so a failure halfway cannot take the ingredients without the recipe.
     */
    public function deleteForGood(Recipe|FreezerItem $meal): bool
    {
        return DB::transaction(function () use ($meal): bool {
            $locked = $meal->newQuery()->lockForUpdate()->find($meal->getKey());

            if ($locked === null) {
                return false;
            }

            if (! MealUsage::of($locked, $this->planner())->canBeDeletedForGood()) {
                return false;
            }

            // Ingredients are polymorphic and are deleted by a model event on the
            // parent, so this has to be a model delete rather than a mass one.
            return (bool) $locked->delete();
        });
    }

    /**
     * Let the planner choose again for the nights that lost their dinner, and say
     * which ones really changed.
     *
     * A re-roll that finds nothing else fitting keeps what the night had and says
     * so ("Kept Chili: nothing else fits.", MM-6). That is deliberate: the shop
     * for that window has already been planned around this meal, so a dinner with
     * an explanation beats an empty night — and it is exactly why the two lists
     * have to be told apart afterwards rather than assumed beforehand.
     *
     * @return array{changed: array<int, UsedNight>, kept: array<int, UsedNight>}
     */
    private function repick(MealUsage $usage): array
    {
        $wanted = [];

        foreach ($usage->nightsToRepick() as $night) {
            $wanted[$night->id] = $night;
        }

        $changed = [];
        $kept = [];

        $nights = PlanNight::query()->whereKey(array_keys($wanted))->get();

        foreach ($nights as $night) {
            $before = $this->mealOn($night);

            $this->planner()->rerollNight($night);

            if ($this->mealOn($night) === $before) {
                $kept[] = $wanted[$night->getKey()];

                continue;
            }

            $changed[] = $wanted[$night->getKey()];
        }

        return ['changed' => $changed, 'kept' => $kept];
    }

    /**
     * What a night is pointing at, as something two reads can be compared on.
     *
     * @return array{0: string|null, 1: int|null, 2: int|null}
     */
    private function mealOn(PlanNight $night): array
    {
        return [$night->selection?->value, $night->recipe_id, $night->freezer_item_id];
    }

    private function planner(): MealPlanner
    {
        return $this->planner ?? new MealPlanner;
    }
}
