<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Grocery\GroceryListBuilder;
use App\Models\PlanGroceryItem;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;

/*
 * What a change to the plan does to a list somebody has already started ticking.
 *
 * "Make the list again whenever the plan changes" has to live with "the checklist
 * is stored" (MM-3): somebody may be standing in the shop with the old one. So
 * the rebuild reconciles rather than replacing — a line that is still needed
 * keeps its tick, a line nothing wants any more goes, a new line arrives
 * unticked, and a tick is dropped only when the plan starts asking for more of
 * something (docs/decisions.md, MM-7).
 *
 * Time is frozen at Sunday 11 January 2026, a shopping day, so "the next window"
 * is Monday the 12th to Wednesday the 14th throughout.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A recipe with one ingredient, which is all most of these need.
 */
function aRecipeFor(string $name, string $ingredient, ?float $quantity = 1, ?string $unit = 'lb'): Recipe
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

function theLineFor(string $name): ?PlanGroceryItem
{
    return PlanGroceryItem::query()->firstWhere('name', $name);
}

it('makes a new list after a re-roll, keeping the ticks that still mean something', function () {
    // The household crossed the beef off at the fridge ("we have some"), then
    // re-rolled Tuesday. Tuesday changing does not make Monday's beef un-bought,
    // and the dinner that left takes its own ingredients with it.
    $chili = aRecipeFor('Chili', 'Ground beef');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');
    $pupusas = aRecipeFor('Pupusas', 'Masa harina', 500, 'g');
    $tacos = aRecipeFor('White Boy Tacos', 'Tortillas', 8, 'count');

    $plan = aPlannedWindowOf($chili, $pasta, $pupusas);

    theLineFor('Ground beef')->check();
    $tickedAt = theLineFor('Ground beef')->checked_at;

    // Only the tacos are left to roll onto Tuesday: the other two are on the
    // nights either side of it.
    (new MealPlanner)->rerollNight(theNight($plan, 1));

    expect(theLineFor('Ground beef')->checked)->toBeTrue()
        ->and(theLineFor('Ground beef')->checked_at->eq($tickedAt))->toBeTrue()
        ->and(theLineFor('Pasta'))->toBeNull()
        ->and(theLineFor('Tortillas'))->not->toBeNull()
        ->and(theLineFor('Tortillas')->checked)->toBeFalse()
        ->and(theLineFor('Tortillas')->meals)->toBe(['White Boy Tacos']);
});

it('takes a line off the list when nothing in the window wants it any more', function () {
    $tacos = aRecipeFor('White Boy Tacos', 'Tortillas', 8, 'count');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');

    $plan = aPlannedWindowOf($tacos, $pasta, $pasta);

    theLineFor('Tortillas')->check();

    (new MealPlanner)->chooseMeal(theNight($plan, 0), $pasta);

    expect(theLineFor('Tortillas'))->toBeNull()
        ->and(theLineFor('Pasta'))->not->toBeNull();
});

it('adds what the re-roll now needs, unticked', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');

    $plan = aPlannedWindowOf($chili, $chili, $chili);

    theLineFor('Ground beef')->check();

    (new MealPlanner)->chooseMeal(theNight($plan, 2), $pasta);

    expect(theLineFor('Pasta')->checked)->toBeFalse()
        // Still two nights of chili, so the beef line stands — at less of it.
        ->and(theLineFor('Ground beef')->checked)->toBeTrue()
        ->and(theLineFor('Ground beef')->quantity)->toBe(2.0);
});

it('drops the tick when the plan starts asking for more of something', function () {
    // "We have enough" was true of 1 lb. A second night of chili makes it 2 lb,
    // and the household has to look again.
    $chili = aRecipeFor('Chili', 'Ground beef');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');

    $plan = aPlannedWindowOf($chili, $pasta, $pasta);

    theLineFor('Ground beef')->check();

    (new MealPlanner)->chooseMeal(theNight($plan, 1), $chili);

    expect(theLineFor('Ground beef')->quantity)->toBe(2.0)
        ->and(theLineFor('Ground beef')->checked)->toBeFalse()
        ->and(theLineFor('Ground beef')->checked_at)->toBeNull();
});

it('keeps the tick when the plan asks for less', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');

    $plan = aPlannedWindowOf($chili, $chili, $pasta);

    theLineFor('Ground beef')->check();

    (new MealPlanner)->chooseMeal(theNight($plan, 1), $pasta);

    expect(theLineFor('Ground beef')->quantity)->toBe(1.0)
        ->and(theLineFor('Ground beef')->checked)->toBeTrue();
});

it('re-labels a line the meals changed under without disturbing its tick', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $tacos = aRecipeFor('White Boy Tacos', 'Ground beef');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');

    $plan = aPlannedWindowOf($chili, $pasta, $pasta);

    theLineFor('Ground beef')->check();

    (new MealPlanner)->chooseMeal(theNight($plan, 0), $tacos);

    expect(theLineFor('Ground beef')->meals)->toBe(['White Boy Tacos'])
        ->and(theLineFor('Ground beef')->checked)->toBeTrue();
});

it('stamps the plan only when the list really moved', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $plan = aPlannedWindowOf($chili);

    $stamped = $plan->refresh()->grocery_list_changed_at;

    Carbon::setTestNow(Carbon::parse('2026-01-11 09:00:00', 'America/Toronto'));

    // Nothing about the window changed, so making the list again is a no-op.
    $update = (new GroceryListBuilder)->rebuild($plan->refresh());

    expect($update->changedAnything())->toBeFalse()
        ->and($plan->refresh()->grocery_list_changed_at->eq($stamped))->toBeTrue()
        ->and($update->summary())->toContain('No change');
});

it('says what moved, so the household can be told', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');

    $plan = aPlannedWindowOf($chili, $chili, $chili);
    theLineFor('Ground beef')->check();

    // Two nights of chili become pasta, without going through the planner, so
    // this rebuild is the one that finds the change.
    foreach ([1, 2] as $index) {
        $plan->refresh()->nights[$index]->forceFill(['recipe_id' => $pasta->id])->save();
    }

    $update = (new GroceryListBuilder)->rebuild($plan->refresh());

    expect($update->changedAnything())->toBeTrue()
        ->and(array_map(fn ($line) => $line->name, $update->added))->toBe(['Pasta'])
        ->and($update->removed)->toBe([])
        ->and($update->unticked)->toBe([])
        ->and($update->summary())->toContain('Pasta — 1000 g added')
        ->and($update->list->lineFor('Ground beef', 'lb')->amount())->toBe('1 lb')
        // Less beef than before, so the tick stands.
        ->and($update->list->lineFor('Ground beef', 'lb')->checked)->toBeTrue();
});

it('keeps one line when a line has somehow been stored twice', function () {
    // An import, a factory or two rebuilds racing each other can leave two rows
    // for one line. The ticked one survives — that tick is somebody's work at the
    // fridge — and the tidy-up is not treated as the list changing.
    $chili = aRecipeFor('Chili', 'Ground beef');
    $plan = aPlannedWindowOf($chili, $chili, $chili);

    $stamped = $plan->refresh()->grocery_list_changed_at;

    PlanGroceryItem::factory()->for($plan)->checked()->create([
        'name' => 'Ground beef',
        'quantity' => 3,
        'unit' => 'lb',
        'store_section' => 'Meat',
        'meals' => ['Chili'],
    ]);

    $update = (new GroceryListBuilder)->rebuild($plan->refresh());

    expect(PlanGroceryItem::query()->where('name', 'Ground beef')->count())->toBe(1)
        ->and(theLineFor('Ground beef')->checked)->toBeTrue()
        ->and($update->changedAnything())->toBeFalse()
        ->and($plan->refresh()->grocery_list_changed_at->eq($stamped))->toBeTrue();
});

it('leaves a window that has already been eaten exactly as it was shopped', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $plan = aPlannedWindowOf($chili);
    theLineFor('Ground beef')->check();

    // Three weeks on: every night of that window has been cooked and eaten.
    Carbon::setTestNow(Carbon::parse('2026-02-01 08:00:00', 'America/Toronto'));
    Recipe::query()->delete();

    $update = (new GroceryListBuilder)->rebuild($plan->refresh());

    expect($update->leftAlone)->not->toBeNull()
        ->and($update->changedAnything())->toBeFalse()
        ->and(theLineFor('Ground beef')->checked)->toBeTrue();
});

it('leaves MM-8 what it needs to warn that the plan changed after the shop', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $pasta = aRecipeFor('Quick Pasta', 'Pasta', 500, 'g');

    $plan = aPlannedWindowOf($chili);

    // The shop is Sunday at 10:00 am, and the list was made at 8:00.
    expect($plan->refresh()->shoppingMoment()->format('Y-m-d H:i'))->toBe('2026-01-11 10:00')
        ->and($plan->groceryListChangedAfterShopping())->toBeFalse();

    // Back from the shop, the household swaps Monday's dinner.
    Carbon::setTestNow(Carbon::parse('2026-01-11 14:00:00', 'America/Toronto'));
    (new MealPlanner)->chooseMeal(theNight($plan, 0), $pasta);

    expect($plan->refresh()->groceryListChangedAfterShopping())->toBeTrue();
});

it('says nothing about the shop when the household no longer shops that day', function () {
    // The shopping days are a setting they can change; a plan keeps the date it
    // was made for. "We can't tell" must not read as "it changed".
    $chili = aRecipeFor('Chili', 'Ground beef');
    $plan = aPlannedWindowOf($chili);

    HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, [
        ['weekday' => 'tuesday', 'time' => '17:00'],
    ]);

    expect($plan->refresh()->shoppingMoment())->toBeNull()
        ->and($plan->groceryListChangedAfterShopping())->toBeFalse();
});

it('makes the list again when a night becomes a night off', function () {
    $chili = aRecipeFor('Chili', 'Ground beef');
    $plan = aPlannedWindowOf($chili, $chili, $chili);

    expect(theLineFor('Ground beef')->quantity)->toBe(3.0);

    (new MealPlanner)->setOverride(theNight($plan, 0), cook: CookAssignment::Nobody, note: 'Eating out');

    expect(theLineFor('Ground beef')->quantity)->toBe(2.0);
});
