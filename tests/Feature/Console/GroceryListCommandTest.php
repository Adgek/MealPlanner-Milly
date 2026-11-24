<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\KidBackupFood;
use App\Models\PlanGroceryItem;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * `milly:groceries` is MM-7's "done when": a way to see a real list from the
 * command line before the plan page (MM-8) exists. The merging is unit-tested
 * (tests/Unit/Grocery) and the storing is too (tests/Feature/Grocery); this is
 * about the command printing the list it was asked for and saying what it did.
 *
 * Time is frozen at Sunday 11 January 2026, a shopping day, so the next window is
 * Mon 12 to Wed 14. The whole output is captured and asserted against, as in
 * PlanWindowCommandTest.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{0: int, 1: string} the exit code and everything it printed
 */
function groceriesWith(string $arguments = ''): array
{
    $status = Artisan::call(trim('milly:groceries '.$arguments));

    return [$status, Artisan::output()];
}

function aDinnerNeeding(string $name, array $ingredients): Recipe
{
    $recipe = Recipe::factory()->create([
        'name' => $name,
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Workhorse30,
    ]);

    foreach ($ingredients as [$ingredient, $quantity, $unit, $section]) {
        $recipe->ingredients()->create([
            'name' => $ingredient,
            'quantity' => $quantity,
            'unit' => $unit,
            'store_section' => $section,
        ]);
    }

    return $recipe;
}

it('prints the list for the window the next shop is for', function () {
    $chili = aDinnerNeeding('Chili', [['Ground beef', 1, 'lb', 'Meat']]);
    aPlannedWindowOf($chili, $chili, $chili);

    [$status, $output] = groceriesWith();

    expect($status)->toBe(0)
        ->and($output)->toContain('Groceries for Sun 11 Jan')
        ->and($output)->toContain('3 nights: Mon 12 Jan to Wed 14 Jan')
        ->and($output)->toContain('Meat')
        ->and($output)->toContain('[ ] Ground beef — 3 lb (Chili)')
        ->and($output)->toContain('1 line, 0 already crossed off');
});

it('shows a line somebody has already crossed off', function () {
    $chili = aDinnerNeeding('Chili', [['Ground beef', 1, 'lb', 'Meat']]);
    aPlannedWindowOf($chili);

    PlanGroceryItem::query()->firstWhere('name', 'Ground beef')->check();

    [, $output] = groceriesWith();

    expect($output)->toContain('[x] Ground beef — 1 lb')
        ->and($output)->toContain('1 already crossed off');
});

it('keeps the kid backup foods and the pantry basics apart', function () {
    KidBackupFood::factory()->create(['name' => 'Chicken nuggets', 'quantity' => 1, 'unit' => 'bag']);
    $chili = aDinnerNeeding('Chili', [['Ground beef', 1, 'lb', 'Meat']]);
    $chili->ingredients()->create([
        'name' => 'Cumin',
        'quantity' => 1,
        'unit' => 'tbsp',
        'store_section' => 'Pantry',
        'pantry_basic' => true,
    ]);

    aPlannedWindowOf($chili);

    [, $output] = groceriesWith();

    expect($output)->toContain('Kid backup')
        ->and($output)->toContain('[ ] Chicken nuggets — 1 bag')
        ->and($output)->toContain('Check you have')
        ->and($output)->toContain('[ ] Cumin — 1 tbsp');
});

it('prints the plain text a chat message carries, and nothing else', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, ['me' => 'Adrian', 'partner' => 'Milly']);
    theWeekIs([Weekday::Monday->value => [CookAssignment::Me, EffortTier::Workhorse30]]);
    $chili = aDinnerNeeding('Chili', [['Ground beef', 1, 'lb', 'Meat']]);

    aPlannedWindowOf($chili);

    [$status, $output] = groceriesWith('--text');

    expect($status)->toBe(0)
        ->and($output)->toContain('Mon 12 Jan: Chili (Adrian)')
        ->and($output)->toContain("Meat\n[ ] Ground beef — 1 lb (Chili)")
        // No footer, no colour, nothing a chat message cannot carry.
        ->and($output)->not->toContain('already crossed off');
});

it('makes the list again when asked, and says what moved', function () {
    $chili = aDinnerNeeding('Chili', [['Ground beef', 1, 'lb', 'Meat']]);
    $tacos = aDinnerNeeding('White Boy Tacos', [['Tortillas', 8, 'count', 'Dry goods']]);
    $pasta = aDinnerNeeding('Quick Pasta', [['Pasta', 500, 'g', 'Dry goods']]);

    $plan = aPlannedWindowOf($chili, $pasta, $pasta);

    // Swapped behind the planner's back, which is the shape of a list that has
    // fallen out of step.
    theNight($plan, 0)->forceFill(['recipe_id' => $tacos->id])->save();

    [, $output] = groceriesWith('--rebuild');

    expect($output)->toContain('[ ] Tortillas — 8 count (White Boy Tacos)')
        ->and($output)->toContain('Tortillas — 8 count added')
        ->and($output)->toContain('Ground beef — 1 lb no longer needed');
});

it('warns when the plan changed after the household had shopped', function () {
    $chili = aDinnerNeeding('Chili', [['Ground beef', 1, 'lb', 'Meat']]);
    $tacos = aDinnerNeeding('White Boy Tacos', [['Tortillas', 8, 'count', 'Dry goods']]);

    $plan = aPlannedWindowOf($chili);

    // The Sunday shop is at 10:00 am; this is the afternoon.
    Carbon::setTestNow(Carbon::parse('2026-01-11 14:00:00', 'America/Toronto'));
    (new MealPlanner)->chooseMeal(theNight($plan, 0), $tacos);

    [, $output] = groceriesWith();

    expect($output)->toContain('The plan changed after the shop');
});

it('leaves a window that has been eaten as it was shopped', function () {
    $chili = aDinnerNeeding('Chili', [['Ground beef', 1, 'lb', 'Meat']]);
    aPlannedWindowOf($chili);

    Carbon::setTestNow(Carbon::parse('2026-02-01 08:00:00', 'America/Toronto'));

    [$status, $output] = groceriesWith('--shopping-date=2026-01-11 --rebuild');

    expect($status)->toBe(0)
        ->and($output)->toContain('stands as it was shopped')
        ->and($output)->toContain('[ ] Ground beef — 1 lb');
});

it('says so when the window has no plan yet', function () {
    [$status, $output] = groceriesWith();

    expect($status)->toBe(1)
        ->and($output)->toContain('No plan for the Sun 11 Jan shop yet');
});

it('says so when there is nothing to buy', function () {
    theWeekIs(cook: CookAssignment::Nobody);
    (new MealPlanner)->planWindow();

    [$status, $output] = groceriesWith();

    expect($status)->toBe(0)
        ->and($output)->toContain('Nothing to buy for this window');
});

it('refuses a shopping date it cannot read', function () {
    [$status, $output] = groceriesWith('--shopping-date=not-a-date');

    expect($status)->toBe(1)
        ->and($output)->not->toBeEmpty();
});
