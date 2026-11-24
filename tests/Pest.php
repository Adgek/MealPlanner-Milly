<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Grocery\IngredientNeed;
use App\Models\CookScheduleNight;
use App\Planning\CalendarDate;
use App\Planning\FreezerOption;
use App\Planning\NightBrief;
use App\Planning\NothingFitsReason;
use App\Planning\Pick;
use App\Planning\PlanningRules;
use App\Planning\RecipeOption;
use App\Planning\ShoppingCalendar;
use App\Planning\WeeklyCookSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test suites
|--------------------------------------------------------------------------
|
| Feature tests boot the app and run against the real MySQL test database
| (see phpunit.xml). RefreshDatabase migrates it before each test, so the
| suite also proves the migrations still apply cleanly.
|
| Unit tests get neither the framework nor a database. Planning logic
| (MM-6, MM-7) belongs there: see AGENTS.md.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Keeps `test` independent of `build`; the asset manifest is the build's job.
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Planning builders (MM-6)
|--------------------------------------------------------------------------
|
| The picker works with plain values rather than models, so its unit tests
| need no factories — just these, to keep each test down to the one rule it
| is about. Defaults are deliberately boring: a both-tagged 30-minute recipe
| on a together night, which every rule then varies one piece of.
|
| None of this touches the database or boots the framework.
|
*/

/**
 * A calendar date, the way the planner carries one.
 */
function aDate(string $date): CarbonImmutable
{
    return CalendarDate::parse($date);
}

function aRecipe(
    int $id,
    CookableBy $cookableBy = CookableBy::Both,
    EffortTier $effort = EffortTier::Workhorse30,
    ?string $lastCookedOn = null,
    ?string $name = null,
): RecipeOption {
    return new RecipeOption(
        id: $id,
        name: $name ?? "Recipe {$id}",
        cookableBy: $cookableBy,
        effortTier: $effort,
        lastCookedOn: $lastCookedOn === null ? null : CarbonImmutable::parse($lastCookedOn),
    );
}

function aFreezerMeal(int $id, int $portionsLeft = 2, ?string $name = null): FreezerOption
{
    return new FreezerOption(
        id: $id,
        name: $name ?? "Freezer meal {$id}",
        portionsLeft: $portionsLeft,
    );
}

function aNight(
    string $date,
    CookAssignment $cook = CookAssignment::Together,
    EffortTier $timeBudget = EffortTier::Workhorse30,
    bool $overridesSchedule = false,
    ?string $note = null,
): NightBrief {
    return new NightBrief(
        date: CarbonImmutable::parse($date),
        cook: $cook,
        timeBudget: $timeBudget,
        overridesSchedule: $overridesSchedule,
        note: $note,
    );
}

/**
 * Everything a pick has to say, rendered with the enums' own labels.
 *
 * A unit test has no Settings to read the household's cook names out of, which is
 * exactly the point: Pick::explain() asks its caller what to call the cook, so
 * here the caller answers with the enum. Anything a person reads passes
 * HouseholdSettings::cookLabel(...) instead.
 */
function theReasonFor(Pick $pick): ?string
{
    return $pick->explain(fn (CookAssignment $cook) => $cook->label());
}

/**
 * A "nothing fits" reason, for the tests that only care that there is one.
 */
function aNothingFitsReason(
    CookAssignment $cook = CookAssignment::Together,
    EffortTier $timeAvailable = EffortTier::Workhorse30,
): NothingFitsReason {
    return new NothingFitsReason(
        cook: $cook,
        timeAvailable: $timeAvailable,
        cappedFrom: null,
        recipesInVault: 0,
        ruledOutByCook: 0,
        ruledOutByTime: 0,
        recipesTaken: 0,
        freezerInStash: 0,
        freezerAllowed: false,
        freezerEmpty: 0,
        freezerTaken: 0,
    );
}

/**
 * The household's starting rules, with any of them changed for one test.
 */
function theRules(
    EffortTier $partnerSoloEffortCap = EffortTier::Chaos15,
    int $varietyLookbackDays = 21,
    bool $freezerMealsOnChaosNightsOnly = true,
): PlanningRules {
    return new PlanningRules(
        partnerSoloEffortCap: $partnerSoloEffortCap,
        varietyLookbackDays: $varietyLookbackDays,
        freezerMealsOnChaosNightsOnly: $freezerMealsOnChaosNightsOnly,
    );
}

/**
 * A whole week of the same night, with named weekdays changed.
 *
 * @param  array<string, array{0: CookAssignment, 1: EffortTier}>  $changed  keyed by weekday value
 */
function aWeek(
    array $changed = [],
    CookAssignment $cook = CookAssignment::Together,
    EffortTier $timeBudget = EffortTier::Workhorse30,
): WeeklyCookSchedule {
    return new WeeklyCookSchedule(array_map(fn (Weekday $weekday) => [
        'weekday' => $weekday,
        'cook' => $changed[$weekday->value][0] ?? $cook,
        'timeBudget' => $changed[$weekday->value][1] ?? $timeBudget,
    ], Weekday::week()));
}

function aShoppingCalendar(Weekday ...$days): ShoppingCalendar
{
    return new ShoppingCalendar($days === [] ? [Weekday::Sunday, Weekday::Wednesday] : $days);
}

/*
|--------------------------------------------------------------------------
| Grocery builders (MM-7)
|--------------------------------------------------------------------------
|
| The merge works with plain values too, so its unit tests need one thing a
| recipe's ingredient row: a need. Defaults to the awkward end of the range
| — no amount, no unit, no section — so a test says only what it is about.
|
*/

function aNeed(
    string $name,
    ?float $quantity = null,
    ?string $unit = null,
    ?string $section = null,
    bool $pantryBasic = false,
    ?string $meal = null,
): IngredientNeed {
    return new IngredientNeed(
        name: $name,
        quantity: $quantity,
        unit: $unit,
        storeSection: $section,
        pantryBasic: $pantryBasic,
        meal: $meal,
    );
}

/*
|--------------------------------------------------------------------------
| Household fixtures (feature tests only)
|--------------------------------------------------------------------------
|
| RefreshDatabase leaves an empty database, and the planner reads the weekly
| cook schedule out of it. This writes a week the way the Settings page would.
|
*/

/**
 * Set the whole weekly cook schedule, with named weekdays changed.
 *
 * @param  array<string, array{0: CookAssignment, 1: EffortTier}>  $changed  keyed by weekday value
 */
function theWeekIs(
    array $changed = [],
    CookAssignment $cook = CookAssignment::Together,
    EffortTier $timeBudget = EffortTier::Workhorse30,
): void {
    foreach (Weekday::week() as $weekday) {
        CookScheduleNight::query()->updateOrCreate(
            ['weekday' => $weekday->value],
            [
                'cook' => $changed[$weekday->value][0] ?? $cook,
                'time_budget' => $changed[$weekday->value][1] ?? $timeBudget,
            ],
        );
    }
}
