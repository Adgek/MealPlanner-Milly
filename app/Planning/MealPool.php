<?php

namespace App\Planning;

use App\Models\FreezerItem;
use App\Models\MealHistoryEntry;
use App\Models\Recipe;

/**
 * Everything dinner could be, plus what this window has already committed to.
 *
 * The pool is the ledger that makes two of the rules work across a whole window
 * rather than one night at a time:
 *
 *   - no meal twice in one window;
 *   - a planned freezer meal reserves one portion, and a re-roll releases it.
 *
 * It is deliberately mutable: picking walks the nights in order and each pick
 * narrows what is left for the rest. Taking a pick back (a re-roll) hands the
 * portion straight back.
 */
final class MealPool
{
    /** @var array<int, RecipeOption> */
    private array $recipes;

    /** @var array<int, FreezerOption> */
    private array $freezerMeals;

    /**
     * Meal keys already used in this window.
     *
     * @var array<string, true>
     */
    private array $used = [];

    /**
     * Portions of each freezer item this window has reserved, by item id.
     *
     * @var array<int, int>
     */
    private array $reserved = [];

    /**
     * @param  array<int, RecipeOption>  $recipes
     * @param  array<int, FreezerOption>  $freezerMeals
     */
    public function __construct(array $recipes = [], array $freezerMeals = [])
    {
        // Sorted by id, so the same seed picks the same meals whatever order the
        // rows came back in.
        usort($recipes, fn (RecipeOption $a, RecipeOption $b) => $a->id <=> $b->id);
        usort($freezerMeals, fn (FreezerOption $a, FreezerOption $b) => $a->id <=> $b->id);

        $this->recipes = $recipes;
        $this->freezerMeals = $freezerMeals;
    }

    /**
     * The vault and the stash as they stand in the database.
     *
     * Freezer items with nothing left are left out: there is no night to plan
     * around an empty box.
     *
     * So is anything the household has retired (MM-15). A retired recipe or
     * freezer meal is still a row — meal history and the nights that have gone by
     * keep pointing at it — but the picker stops choosing it, which is what
     * "remove it from the vault" has to mean when removing the row itself would
     * destroy the record of having cooked it.
     */
    public static function fromVault(): self
    {
        $lastCooked = MealHistoryEntry::query()
            ->selectRaw('recipe_id, MAX(cooked_on) as last_cooked_on')
            ->groupBy('recipe_id')
            ->pluck('last_cooked_on', 'recipe_id');

        $recipes = Recipe::query()
            ->inTheVault()
            ->orderBy('id')
            ->get()
            ->map(fn (Recipe $recipe) => RecipeOption::fromModel(
                $recipe,
                ($date = $lastCooked->get($recipe->id)) ? CalendarDate::parse((string) $date) : null,
            ))
            ->all();

        $freezerMeals = FreezerItem::query()
            ->inTheVault()
            ->where('portions_left', '>', 0)
            ->orderBy('id')
            ->get()
            ->map(fn (FreezerItem $item) => FreezerOption::fromModel($item))
            ->all();

        return new self($recipes, $freezerMeals);
    }

    /**
     * @return array<int, RecipeOption>
     */
    public function recipes(): array
    {
        return $this->recipes;
    }

    /**
     * @return array<int, FreezerOption>
     */
    public function freezerMeals(): array
    {
        return $this->freezerMeals;
    }

    public function option(string $key): ?MealOption
    {
        foreach ([...$this->recipes, ...$this->freezerMeals] as $option) {
            if ($option->key() === $key) {
                return $option;
            }
        }

        return null;
    }

    public function isUsed(string $key): bool
    {
        return isset($this->used[$key]);
    }

    /**
     * How many portions of this freezer meal are still free to plan around.
     */
    public function portionsAvailable(FreezerOption $option): int
    {
        return $option->portionsLeft - ($this->reserved[$option->id] ?? 0);
    }

    /**
     * Commit to a meal: it is used up for this window, and a freezer meal also
     * reserves a portion.
     */
    public function take(MealOption $option): void
    {
        $this->used[$option->key()] = true;

        if ($option instanceof FreezerOption) {
            $this->reserved[$option->id] = ($this->reserved[$option->id] ?? 0) + 1;
        }
    }

    /**
     * Record that a meal is spoken for without reserving anything.
     *
     * This is how a locked night joins the window: its portion was already taken
     * out of the stash when it was planned, so re-counting it here would spend
     * the same portion twice.
     */
    public function markUsed(string $key): void
    {
        $this->used[$key] = true;
    }

    /**
     * Give a meal back: a re-roll releases the night's hold on it, portion included.
     */
    public function release(MealOption $option): void
    {
        unset($this->used[$option->key()]);

        if ($option instanceof FreezerOption && ($this->reserved[$option->id] ?? 0) > 0) {
            $this->reserved[$option->id]--;
        }
    }
}
