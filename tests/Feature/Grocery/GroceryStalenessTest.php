<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Grocery\GroceryListBuilder;
use App\Models\Ingredient;
use App\Models\KidBackupFood;
use App\Models\PlanGroceryItem;
use App\Models\Recipe;
use Illuminate\Support\Carbon;

/*
 * A list can fall behind without the plan changing at all.
 *
 * The planner makes the list again whenever a dinner changes, but editing a
 * recipe's ingredients or the kid off-ramp inventory touches no plan — and MM-15
 * is adding screens for exactly that. Nothing stored moves, so MM-8 and MM-9
 * would show a list that quietly predates the edit, with no warning either,
 * because `grocery_list_changed_at` never moved.
 *
 * Time is frozen at Sunday 11 January 2026, a shopping day, so the next window is
 * Mon 12 to Wed 14.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

function aDinner(string $name, string $ingredient = 'Ground beef', ?float $quantity = 1, ?string $unit = 'lb'): Recipe
{
    $recipe = Recipe::factory()->create([
        'name' => $name,
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Workhorse30,
    ]);

    $recipe->ingredients()->create([
        'name' => $ingredient,
        'quantity' => $quantity,
        'unit' => $unit,
        'store_section' => 'Meat',
    ]);

    return $recipe;
}

it('calls a list current when it matches the window', function () {
    $plan = aPlannedWindowOf(aDinner('Chili'));

    expect((new GroceryListBuilder)->isStale($plan))->toBeFalse();
});

it('notices a kid backup food added since the list was made', function () {
    $plan = aPlannedWindowOf(aDinner('Chili'));
    $groceries = new GroceryListBuilder;

    KidBackupFood::factory()->create(['name' => 'Chicken nuggets', 'quantity' => 1, 'unit' => 'bag']);

    expect($groceries->isStale($plan))->toBeTrue()
        // The stored list is still the old one: nothing has made it again.
        ->and($groceries->listFor($plan)->lineFor('Chicken nuggets', 'bag'))->toBeNull();
});

it('notices a recipe whose ingredients have been edited', function () {
    $chili = aDinner('Chili');
    $plan = aPlannedWindowOf($chili);
    $groceries = new GroceryListBuilder;

    $chili->ingredients()->first()->update(['quantity' => 2]);

    expect($groceries->isStale($plan))->toBeTrue();

    $chili->ingredients()->create(['name' => 'Cumin', 'quantity' => 1, 'unit' => 'tbsp', 'store_section' => 'Pantry']);

    expect($groceries->isStale($plan))->toBeTrue();
});

it('makes a stale list current when the household looks at it', function () {
    $plan = aPlannedWindowOf(aDinner('Chili'));
    $groceries = new GroceryListBuilder;

    PlanGroceryItem::query()->firstWhere('name', 'Ground beef')->check();
    KidBackupFood::factory()->create(['name' => 'Chicken nuggets', 'quantity' => 1, 'unit' => 'bag']);

    Carbon::setTestNow(Carbon::parse('2026-01-11 09:30:00', 'America/Toronto'));

    $list = $groceries->currentFor($plan);

    expect($list->lineFor('Chicken nuggets', 'bag')->amount())->toBe('1 bag')
        // The tick the household already made is still theirs.
        ->and($list->lineFor('Ground beef', 'lb')->checked)->toBeTrue()
        ->and($groceries->isStale($plan->refresh()))->toBeFalse()
        // And MM-8 has something to warn with, because the list really did move.
        ->and($plan->refresh()->grocery_list_changed_at->format('H:i'))->toBe('14:30');
});

it('posts the list as it is now, not as it was', function () {
    // MM-9 reads the text; a list that predates an edit must not be what it posts.
    $plan = aPlannedWindowOf(aDinner('Chili'));

    KidBackupFood::factory()->create(['name' => 'Chicken nuggets', 'quantity' => 1, 'unit' => 'bag']);

    expect((new GroceryListBuilder)->textFor($plan))->toContain('[ ] Chicken nuggets — 1 bag');
});

it('leaves a window that is over alone, however the vault has changed since', function () {
    $chili = aDinner('Chili');
    $plan = aPlannedWindowOf($chili);

    Carbon::setTestNow(Carbon::parse('2026-02-01 08:00:00', 'America/Toronto'));
    Ingredient::query()->update(['quantity' => 9]);

    $groceries = new GroceryListBuilder;

    expect($groceries->isStale($plan))->toBeFalse()
        ->and($groceries->currentFor($plan)->lineFor('Ground beef', 'lb')->amount())->toBe('1 lb');
});
