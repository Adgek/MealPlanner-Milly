<?php

namespace App\Vault;

use App\Models\FreezerItem;
use App\Models\KitchenProject;
use App\Models\MealHistoryEntry;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use Illuminate\Support\Str;

/**
 * Everything that still points at a recipe or a freezer meal, and what that
 * means for removing it (MM-15).
 *
 * This is the class behind the warning the household reads before pressing
 * "remove", and behind the one rule that keeps the data honest:
 *
 *   **Nothing is deleted outright while anything points at it.** It is retired
 *   instead — the row stays, the picker stops choosing it (MealPool::fromVault()),
 *   and every plan night and history row that points at it goes on being true.
 *
 * That rule exists because the alternative is silent damage.
 * `meal_history.recipe_id` is `cascadeOnDelete`, so deleting a recipe destroys
 * every record of the household having cooked it — the only input MM-6's variety
 * weighting has. `plan_nights.recipe_id` and `freezer_item_id` are
 * `nullOnDelete`, so a delete leaves a night claiming `selection = 'recipe'` with
 * no recipe, which MM-8, MM-9 and MM-14 would each render as a blank dinner; and
 * a freezer night also holds a portion out of the stash
 * (`plan_nights.holds_freezer_portion`), so the reservation would point at
 * nothing.
 *
 * The counting is done in the static constructors; everything else works on
 * plain values, so the rules and the wording are unit-tested with no database
 * behind them (AGENTS.md).
 */
final class MealUsage
{
    /**
     * @param  array<int, UsedNight>  $nights  every plan night pointing at it
     * @param  int  $timesCooked  rows in meal history (recipes only; freezer portions are not history)
     * @param  array<int, string>  $restockedBy  kitchen projects that top it up (freezer items only)
     * @param  int|null  $portionsLeft  what is still counted in the stash (freezer items only)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $nights = [],
        public readonly int $timesCooked = 0,
        public readonly array $restockedBy = [],
        public readonly ?int $portionsLeft = null,
    ) {}

    /**
     * What points at this meal, as the database has it.
     */
    public static function of(Recipe|FreezerItem $meal, ?MealPlanner $planner = null): self
    {
        return $meal instanceof Recipe
            ? self::forRecipe($meal, $planner)
            : self::forFreezerItem($meal, $planner);
    }

    public static function forRecipe(Recipe $recipe, ?MealPlanner $planner = null): self
    {
        return new self(
            name: $recipe->name,
            nights: self::nightsPointingAt('recipe_id', (int) $recipe->getKey(), $planner),
            timesCooked: MealHistoryEntry::query()->where('recipe_id', $recipe->getKey())->count(),
        );
    }

    public static function forFreezerItem(FreezerItem $item, ?MealPlanner $planner = null): self
    {
        return new self(
            name: $item->name,
            nights: self::nightsPointingAt('freezer_item_id', (int) $item->getKey(), $planner),
            restockedBy: KitchenProject::query()
                ->where('produces_freezer_item_id', $item->getKey())
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            portionsLeft: $item->portions_left,
        );
    }

    // -----------------------------------------------------------------
    // What removing it may do
    // -----------------------------------------------------------------

    /**
     * Does anything at all point at it?
     */
    public function isUnused(): bool
    {
        return $this->nights === []
            && $this->timesCooked === 0
            && $this->restockedBy === [];
    }

    /**
     * Has the household actually eaten this?
     *
     * Either meal history says so, or a night that has gone by does — and a night
     * that has gone by is the only record that it happened at all (MM-6).
     */
    public function hasBeenEaten(): bool
    {
        return $this->timesCooked > 0 || $this->pastNights() !== [];
    }

    /**
     * May the row itself go?
     *
     * Only when nothing points at it, which makes the damage the foreign keys
     * would do structurally impossible rather than merely warned about. A recipe
     * typed by mistake can still be deleted; one that has been cooked, or is on a
     * plan, is retired instead.
     */
    public function canBeDeletedForGood(): bool
    {
        return $this->isUnused();
    }

    /**
     * Why it cannot be deleted outright, for the household to read.
     */
    public function whyItCannotBeDeleted(): ?string
    {
        return match (true) {
            $this->canBeDeletedForGood() => null,
            $this->hasBeenEaten() => 'You have eaten this, and deleting it would take that record with it.',
            $this->upcomingNights() !== [] => 'It is on a plan right now.',
            $this->restockedBy !== [] => self::listOf($this->restockedBy).' still '
                .(count($this->restockedBy) === 1 ? 'restocks' : 'restock').' it.',
            default => 'Something still points at it.',
        };
    }

    /**
     * @return array<int, UsedNight>
     */
    public function pastNights(): array
    {
        return $this->nightsWhere(fn (UsedNight $night) => $night->hasPassed);
    }

    /**
     * @return array<int, UsedNight>
     */
    public function upcomingNights(): array
    {
        return $this->nightsWhere(fn (UsedNight $night) => ! $night->hasPassed);
    }

    /**
     * The nights the planner will pick again, because removing the meal takes it
     * off them.
     *
     * @return array<int, UsedNight>
     */
    public function nightsToRepick(): array
    {
        return $this->nightsWhere(fn (UsedNight $night) => $night->canBeRepicked());
    }

    /**
     * The nights that keep this meal: the household locked them.
     *
     * A lock is the household's answer for that night and it outranks the picking
     * rules (MM-6), so retiring a meal does not take it off a locked night. It is
     * also what the household usually means — "we are making this one last time
     * on Friday, and never again".
     *
     * @return array<int, UsedNight>
     */
    public function lockedNights(): array
    {
        return $this->nightsWhere(fn (UsedNight $night) => ! $night->hasPassed && $night->isLocked);
    }

    /**
     * @return array<int, int>
     */
    public function nightIdsToRepick(): array
    {
        return array_map(fn (UsedNight $night) => $night->id, $this->nightsToRepick());
    }

    // -----------------------------------------------------------------
    // What the household reads before pressing the button
    // -----------------------------------------------------------------

    /**
     * The whole truth about removing it, one sentence per thing it touches.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return array_values(array_filter([
            $this->aboutUpcomingNights(),
            $this->aboutLockedNights(),
            $this->aboutPastNights(),
            $this->aboutHistory(),
            $this->aboutPortions(),
            $this->aboutRestocking(),
        ]));
    }

    /**
     * What removing it did, once it is done: the banner the household reads
     * afterwards.
     *
     * Past tense, and it answers the two questions the warning raised — what
     * happened to the nights, and whether anything was lost.
     */
    public function whatRemovingDid(): string
    {
        $sentences = [$this->name.' is out of the vault, and the picker will not choose it again.'];

        if (($repicked = $this->nightsToRepick()) !== []) {
            $sentences[] = sprintf(
                '%s now %s a different dinner.',
                $this->datesOf($repicked),
                count($repicked) === 1 ? 'has' : 'have',
            );
        }

        if (($locked = $this->lockedNights()) !== []) {
            $sentences[] = sprintf(
                '%s %s it: %s locked.',
                $this->datesOf($locked),
                count($locked) === 1 ? 'keeps' : 'keep',
                count($locked) === 1 ? 'that night is' : 'those nights are',
            );
        }

        if ($this->hasBeenEaten()) {
            $sentences[] = 'Nothing you have cooked was lost.';
        }

        return implode(' ', $sentences);
    }

    private function aboutUpcomingNights(): ?string
    {
        $nights = $this->nightsToRepick();

        if ($nights === []) {
            return null;
        }

        $onThePlanThatIsOut = array_filter($nights, fn (UsedNight $night) => $night->isCurrentPlan) !== [];

        return sprintf(
            'It is dinner on %s%s, so removing it picks something else for %s.',
            $this->datesOf($nights),
            $onThePlanThatIsOut ? ' on the plan that is out now' : '',
            count($nights) === 1 ? 'that night' : 'those nights',
        );
    }

    private function aboutLockedNights(): ?string
    {
        $nights = $this->lockedNights();

        if ($nights === []) {
            return null;
        }

        return sprintf(
            '%s %s locked, so %s keeps this meal until you unlock %s on the plan page.',
            $this->datesOf($nights),
            count($nights) === 1 ? 'is' : 'are',
            count($nights) === 1 ? 'it' : 'they',
            count($nights) === 1 ? 'it' : 'them',
        );
    }

    private function aboutPastNights(): ?string
    {
        $nights = $this->pastNights();

        if ($nights === []) {
            return null;
        }

        return sprintf(
            'The plan says you ate it on %s. %s stays as %s.',
            $this->datesOf($nights),
            count($nights) === 1 ? 'That night' : 'Those nights',
            count($nights) === 1 ? 'it is' : 'they are',
        );
    }

    private function aboutHistory(): ?string
    {
        if ($this->timesCooked === 0) {
            return null;
        }

        return sprintf(
            'You have cooked it %d %s. Removing it keeps that record, because the picker reads it to '
            .'know what you have had recently.',
            $this->timesCooked,
            Str::plural('time', $this->timesCooked),
        );
    }

    private function aboutPortions(): ?string
    {
        if ($this->portionsLeft === null || $this->portionsLeft < 1) {
            return null;
        }

        return sprintf(
            '%d %s still counted in the stash. The count is left alone, but the picker stops planning '
            .'%s — eat them first, or bring it back later.',
            $this->portionsLeft,
            $this->portionsLeft === 1 ? 'portion is' : 'portions are',
            $this->portionsLeft === 1 ? 'it' : 'them',
        );
    }

    private function aboutRestocking(): ?string
    {
        if ($this->restockedBy === []) {
            return null;
        }

        return sprintf(
            '%s %s it, and will go on doing so until you change %s.',
            self::listOf($this->restockedBy),
            count($this->restockedBy) === 1 ? 'restocks' : 'restock',
            count($this->restockedBy) === 1 ? 'that project' : 'those projects',
        );
    }

    /**
     * @param  array<int, UsedNight>  $nights
     */
    private function datesOf(array $nights): string
    {
        return self::listOf(array_map(fn (UsedNight $night) => $night->label(), $nights));
    }

    /**
     * "Tue 25 Nov", "Tue 25 Nov and Fri 28 Nov", "Mon 24 Nov, Tue 25 Nov and 3 other nights".
     *
     * Capped, because a recipe the household has been cooking for a year would
     * otherwise print a paragraph of dates on a phone.
     *
     * @param  array<int, string>  $items
     */
    private static function listOf(array $items, int $cap = 3): string
    {
        $items = array_values($items);
        $extra = count($items) - $cap;

        if ($extra > 0) {
            $items = array_slice($items, 0, $cap);
            $items[] = $extra.' other '.Str::plural('night', $extra);
        }

        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * @param  callable(UsedNight): bool  $keep
     * @return array<int, UsedNight>
     */
    private function nightsWhere(callable $keep): array
    {
        return array_values(array_filter($this->nights, $keep));
    }

    /**
     * Every plan night whose given column points at this meal, in date order.
     *
     * The current plan is asked for through MealPlanner rather than derived here:
     * the window the household is living in is the planner's to decide (MM-6).
     *
     * @return array<int, UsedNight>
     */
    private static function nightsPointingAt(string $column, int $id, ?MealPlanner $planner): array
    {
        $planner ??= new MealPlanner;
        $currentPlanId = $planner->planFor($planner->currentWindow())?->getKey();

        return PlanNight::query()
            ->where($column, $id)
            ->orderBy('date')
            ->get()
            ->map(fn (PlanNight $night) => UsedNight::fromPlanNight(
                $night,
                $currentPlanId !== null && $night->plan_id === $currentPlanId,
            ))
            ->all();
    }
}
