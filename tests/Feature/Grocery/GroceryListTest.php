<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Grocery\GroceryListBuilder;
use App\Grocery\GrocerySection;
use App\Grocery\IngredientNeed;
use App\Models\FreezerItem;
use App\Models\KidBackupFood;
use App\Models\KitchenProject;
use App\Models\Plan;
use App\Models\PlanGroceryItem;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;

/*
 * The grocery list against the real database: the window's ingredients combined
 * and stored, the kid foods added, the freezer nights contributing nothing, and
 * the plan kept in step. The merging rules themselves are unit-tested with no
 * database behind them (tests/Unit/Grocery).
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
 * A recipe with its shopping list.
 *
 * @param  array<int, array{0: string, 1: float|int|null, 2: string|null, 3: string|null, 4?: bool}>  $ingredients
 */
function aRecipeThatNeeds(string $name, array $ingredients, EffortTier $effort = EffortTier::Workhorse30): Recipe
{
    $recipe = Recipe::factory()->create([
        'name' => $name,
        'cookable_by' => CookableBy::Both,
        'effort_tier' => $effort,
    ]);

    foreach ($ingredients as $row) {
        [$ingredient, $quantity, $unit, $section] = array_pad($row, 4, null);

        $recipe->ingredients()->create([
            'name' => $ingredient,
            'quantity' => $quantity,
            'unit' => $unit,
            'store_section' => $section,
            'pantry_basic' => $row[4] ?? false,
        ]);
    }

    return $recipe;
}

/**
 * The window planned with one dinner on each night, in the order given.
 */
function aPlanOf(Recipe ...$dinners): Plan
{
    $planner = new MealPlanner;
    $plan = $planner->planWindow();

    foreach ($plan->nights as $index => $night) {
        if (isset($dinners[$index])) {
            $planner->chooseMeal($night, $dinners[$index]);
        }
    }

    return $plan->refresh();
}

it('combines the ingredients of every planned recipe in the window', function () {
    $bulgogi = aRecipeThatNeeds('Beef Bulgogi', [
        ['Ground beef', 1, 'lb', 'Meat'],
        ['Garlic', 3, 'clove', 'Produce'],
    ]);
    $tacos = aRecipeThatNeeds('White Boy Tacos', [
        ['Ground beef', 1, 'lb', 'Meat'],
        ['Tortillas', 8, 'count', 'Dry goods'],
    ]);
    $pasta = aRecipeThatNeeds('Quick Pasta', [
        ['Pasta', 500, 'g', 'Dry goods'],
        ['Garlic', 2, 'clove', 'Produce'],
    ]);

    $list = (new GroceryListBuilder)->listFor(aPlanOf($bulgogi, $tacos, $pasta));

    expect($list->lineFor('Ground beef', 'lb')->amount())->toBe('2 lb')
        ->and($list->lineFor('Ground beef', 'lb')->meals)->toBe(['Beef Bulgogi', 'White Boy Tacos'])
        ->and($list->lineFor('Garlic', 'clove')->amount())->toBe('5 clove')
        ->and($list->lineFor('Garlic', 'clove')->meals)->toBe(['Beef Bulgogi', 'Quick Pasta'])
        ->and($list->lineFor('Tortillas', 'count')->amount())->toBe('8 count')
        ->and($list->lineFor('Pasta', 'g')->amount())->toBe('500 g');
});

it('stores the list so both phones see the same ticks', function () {
    // MM-3: the checklist is stored, not recomputed. The rows are the list.
    $plan = aPlanOf(aRecipeThatNeeds('Chili', [['Ground beef', 1, 'lb', 'Meat']]));

    $row = PlanGroceryItem::query()->where('plan_id', $plan->id)->firstWhere('name', 'Ground beef');

    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(1.0)
        ->and($row->unit)->toBe('lb')
        ->and($row->store_section)->toBe('Meat')
        ->and($row->meals)->toBe(['Chili'])
        ->and($row->checked)->toBeFalse()
        ->and($plan->refresh()->hasGroceryList())->toBeTrue();
});

it('always adds the kid backup foods, even on a week of nights off', function () {
    theWeekIs(cook: CookAssignment::Nobody);
    KidBackupFood::factory()->create(['name' => 'Chicken nuggets', 'quantity' => 1, 'unit' => 'bag']);

    $plan = (new MealPlanner)->planWindow();
    $list = (new GroceryListBuilder)->listFor($plan);

    expect($list->count())->toBe(1)
        ->and($list->lineFor('Chicken nuggets', 'bag')->section)->toBe(IngredientNeed::KID_BACKUP)
        ->and($list->lineFor('Chicken nuggets', 'bag')->meals)->toBe([IngredientNeed::KID_BACKUP]);
});

it('adds nothing for a freezer night', function () {
    // The portion was cooked and frozen weeks ago; there is nothing to buy for it.
    $soup = FreezerItem::query()->create([
        'name' => 'Frozen minestrone',
        'portions_left' => 3,
        'thaw_lead_time_hours' => 24,
    ]);
    $chili = aRecipeThatNeeds('Chili', [['Ground beef', 1, 'lb', 'Meat']]);

    $planner = new MealPlanner;
    $plan = $planner->planWindow();
    $planner->chooseMeal($plan->nights[0], $chili);
    $planner->chooseMeal($plan->nights[1], $soup);
    $planner->chooseMeal($plan->nights[2], $soup);

    $list = (new GroceryListBuilder)->listFor($plan->refresh());

    expect($list->count())->toBe(1)
        ->and($list->lineFor('Ground beef', 'lb')->amount())->toBe('1 lb')
        ->and($list->lineFor('Ground beef', 'lb')->meals)->toBe(['Chili']);
});

it('puts the pantry basics in the short section at the end', function () {
    $plan = aPlanOf(aRecipeThatNeeds('Chili', [
        ['Ground beef', 1, 'lb', 'Meat'],
        ['Cumin', 1, 'tbsp', 'Pantry', true],
    ]));

    $list = (new GroceryListBuilder)->listFor($plan);

    expect($list->sectionNames())->toBe(['Meat', GrocerySection::PANTRY_BASICS])
        ->and($list->pantryBasics->lines[0]->name)->toBe('Cumin')
        ->and(PlanGroceryItem::query()->firstWhere('name', 'Cumin')->pantry_basic)->toBeTrue();
});

it('walks the sections in the order the household wrote down', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_STORE_SECTION_ORDER, ['Produce', 'Meat']);

    $plan = aPlanOf(aRecipeThatNeeds('Chili', [
        ['Ground beef', 1, 'lb', 'Meat'],
        ['Onion', 2, null, 'Produce'],
        ['Tortillas', 8, 'count', 'Dry goods'],
    ]));

    expect((new GroceryListBuilder)->listFor($plan)->sectionNames())
        ->toBe(['Produce', 'Meat', 'Dry goods']);
});

it('ignores a kitchen project’s ingredients, which are MM-12’s', function () {
    $project = KitchenProject::factory()->create(['name' => 'Big batch minestrone']);
    $project->ingredients()->create(['name' => 'Carrots', 'quantity' => 500, 'unit' => 'g']);

    $plan = aPlanOf(aRecipeThatNeeds('Chili', [['Ground beef', 1, 'lb', 'Meat']]));

    expect((new GroceryListBuilder)->listFor($plan)->lineFor('Carrots', 'g'))->toBeNull();
});

it('leaves a window with nothing planned an empty list', function () {
    $plan = Plan::factory()->window('2026-01-11')->create();

    $update = (new GroceryListBuilder)->rebuild($plan);

    expect($update->list->isEmpty())->toBeTrue()
        ->and($update->changedAnything())->toBeFalse()
        // Nothing moved, so there is nothing for MM-8 to warn about.
        ->and($plan->refresh()->hasGroceryList())->toBeFalse();
});

it('leaves no list behind after a dry run', function () {
    // previewWindow() really plans, inside a transaction it then rolls back, so
    // the list it makes on the way has to roll back with it.
    aRecipeThatNeeds('Chili', [['Ground beef', 1, 'lb', 'Meat']]);

    (new MealPlanner)->previewWindow();

    expect(PlanGroceryItem::count())->toBe(0)
        ->and(Plan::count())->toBe(0);
});

it('says what the window asks for without storing any of it', function () {
    $chili = aRecipeThatNeeds('Chili', [['Ground beef', 1, 'lb', 'Meat']]);
    $plan = aPlanOf($chili);
    $tacos = aRecipeThatNeeds('White Boy Tacos', [['Tortillas', 8, 'count', 'Dry goods']]);

    // Swap the dinner behind the planner's back, so the stored list is stale.
    $plan->nights[0]->forceFill(['recipe_id' => $tacos->id])->save();

    $preview = (new GroceryListBuilder)->previewFor($plan->refresh());

    expect($preview->lineFor('Tortillas', 'count'))->not->toBeNull()
        ->and(PlanGroceryItem::query()->where('name', 'Tortillas')->exists())->toBeFalse();
});

it('prints the stored list as plain text, with the household’s own cook names', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, ['me' => 'Adrian', 'partner' => 'Milly']);
    theWeekIs([Weekday::Monday->value => [CookAssignment::Me, EffortTier::Workhorse30]]);

    $plan = aPlanOf(aRecipeThatNeeds('Chili', [
        ['Ground beef', 1, 'lb', 'Meat'],
        ['Cumin', 1, 'tbsp', 'Pantry', true],
    ]));

    $text = (new GroceryListBuilder)->textFor($plan);

    expect($text)->toContain('Groceries for Sun 11 Jan')
        ->and($text)->toContain('Mon 12 Jan: Chili (Adrian)')
        ->and($text)->toContain("Meat\n[ ] Ground beef — 1 lb (Chili)")
        ->and($text)->toContain("Check you have\n[ ] Cumin — 1 tbsp (Chili)");
});
