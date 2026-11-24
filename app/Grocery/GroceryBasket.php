<?php

namespace App\Grocery;

use App\Enums\NightSelection;
use App\Planning\Pick;

/**
 * Everything a window asks for, before any of it is merged.
 *
 * It takes the picks (MM-6's `Pick` — the night and what was chosen) and the
 * ingredients of the recipes they chose, and says which of them the shop has to
 * cover. Plain values on both sides, so "a freezer night adds nothing" is a unit
 * test rather than something you find out at the till (AGENTS.md).
 */
final class GroceryBasket
{
    /**
     * The needs of one window.
     *
     * Only a night with a recipe on it contributes:
     *
     *   - a **freezer meal** needs no shopping. It was cooked and frozen weeks
     *     ago, and the portion it holds is the record of that (MM-6);
     *   - a **night off** is leftovers, travel or eating out — there is no dinner
     *     to buy for;
     *   - a night **nothing fitted** has no meal, and must not quietly fall
     *     through as if it had one: MM-6 stores that as a real state, and the
     *     household is being asked to add a recipe, not to buy ingredients for a
     *     dinner that does not exist;
     *   - a night **nobody has planned yet** likewise.
     *
     * Each need is labelled with the meal that wants it, so the list can show
     * which dinners each line is for.
     *
     * $alwaysNeeded — the kid backup foods — goes on the end, after the meals, so
     * that anything a recipe also asks for keeps the recipe's spelling and its
     * store section when the two merge.
     *
     * @param  array<int, Pick>  $picks  the window's nights, in order
     * @param  array<int, array<int, IngredientNeed>>  $needsByRecipe  keyed by recipe id
     * @param  array<int, IngredientNeed>  $alwaysNeeded
     * @return array<int, IngredientNeed>
     */
    public static function needs(array $picks, array $needsByRecipe, array $alwaysNeeded = []): array
    {
        $needs = [];

        foreach ($picks as $pick) {
            if ($pick->selection !== NightSelection::Recipe || $pick->recipeId === null) {
                continue;
            }

            foreach ($needsByRecipe[$pick->recipeId] ?? [] as $need) {
                $needs[] = $need->withMeal($pick->mealName);
            }
        }

        return [...$needs, ...array_values($alwaysNeeded)];
    }

    /**
     * The recipes a window actually needs the ingredients of.
     *
     * @param  array<int, Pick>  $picks
     * @return array<int, int>
     */
    public static function recipeIds(array $picks): array
    {
        $ids = [];

        foreach ($picks as $pick) {
            if ($pick->selection === NightSelection::Recipe && $pick->recipeId !== null) {
                $ids[$pick->recipeId] = true;
            }
        }

        return array_keys($ids);
    }
}
