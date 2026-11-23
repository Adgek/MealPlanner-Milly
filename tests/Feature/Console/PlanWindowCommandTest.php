<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Enums\Weekday;
use App\Models\CookScheduleNight;
use App\Models\FreezerItem;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * The command is MM-6's "done when": it prints the next window's plan from the
 * current data. The picking rules themselves are unit-tested (tests/Unit); this is
 * about the command saying what it did, and not saving anything on a dry run.
 *
 * Time is frozen at Sunday 11 January 2026, so the next window is Mon 12 to Wed 14.
 *
 * The whole output is captured and asserted against, rather than Laravel's
 * expectsOutputToContain: several of the things worth checking land on one line of
 * a table, and that matcher only credits one expectation per line written.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{0: int, 1: string} the exit code and everything it printed
 */
function plannedWith(string $arguments = ''): array
{
    $status = Artisan::call(trim('milly:plan '.$arguments));

    return [$status, Artisan::output()];
}

it('prints the next window and saves it', function () {
    Recipe::factory()->count(5)->create([
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Workhorse30,
    ]);

    [$status, $output] = plannedWith();

    expect($status)->toBe(0)
        ->and($output)->toContain('Shopping Sun 11 Jan')
        ->and($output)->toContain('3 nights: Mon 12 Jan to Wed 14 Jan')
        ->and($output)->toContain('Mon 12 Jan')
        ->and($output)->toContain('Tue 13 Jan')
        ->and($output)->toContain('Wed 14 Jan')
        ->and($output)->toContain('Together')
        ->and($output)->toContain('30-min workhorse')
        ->and($output)->toContain('Saved.')
        ->and(Plan::count())->toBe(1)
        ->and(PlanNight::whereNotNull('recipe_id')->count())->toBe(3);
});

it('prints the dinners it chose', function () {
    theWeekIs([Weekday::Monday->value => [CookAssignment::Me, EffortTier::Project60]], CookAssignment::Nobody);
    Recipe::factory()->create([
        'name' => 'Chili',
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Project60,
    ]);

    [, $output] = plannedWith();

    expect($output)->toContain('Chili');
});

it('calls the cooks by the household’s own names', function () {
    // MM-4 put the cooks' names in Settings, and decided that anything showing a
    // cook to a person reads them from there. The plan is the first thing that
    // does, so renaming a cook has to rename them in this table.
    HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, ['me' => 'Adrian', 'partner' => 'Milly']);
    theWeekIs([
        Weekday::Monday->value => [CookAssignment::Me, EffortTier::Workhorse30],
        Weekday::Tuesday->value => [CookAssignment::Partner, EffortTier::Chaos15],
    ], CookAssignment::Nobody);
    Recipe::factory()->count(4)->create([
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Chaos15,
    ]);

    [, $output] = plannedWith();

    expect($output)->toContain('Adrian')
        ->and($output)->toContain('Milly')
        ->and($output)->not->toContain('Partner');
});

it('prints a freezer meal as coming out of the freezer', function () {
    theWeekIs([Weekday::Monday->value => [CookAssignment::Partner, EffortTier::Chaos15]], CookAssignment::Nobody);
    FreezerItem::factory()->portions(3)->create(['name' => 'Frozen minestrone']);

    [, $output] = plannedWith();

    expect($output)->toContain('Frozen minestrone (from the freezer)');
});

it('prints a night off with the household’s own reason', function () {
    theWeekIs([], CookAssignment::Nobody, EffortTier::Chaos15);

    [, $output] = plannedWith();

    expect($output)->toContain('Nobody cooking');
});

it('warns about the nights nothing fits, and still saves the rest', function () {
    theWeekIs([Weekday::Monday->value => [CookAssignment::Me, EffortTier::Project60]], CookAssignment::Partner);
    // Nothing my partner would make on her own, so Tuesday and Wednesday are stuck.
    Recipe::factory()->create([
        'name' => 'Chili',
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Project60,
    ]);

    [$status, $output] = plannedWith();

    expect($status)->toBe(0)
        ->and($output)->toContain('Nothing fits')
        ->and($output)->toContain('not tagged for Partner')
        ->and($output)->toContain('2 night(s) have nothing that fits')
        ->and($output)->toContain('The rest of the plan stands')
        // Chili still got planned.
        ->and($output)->toContain('Chili')
        ->and(PlanNight::where('selection', NightSelection::NothingFits->value)->count())->toBe(2)
        ->and(PlanNight::whereNotNull('recipe_id')->count())->toBe(1);
});

it('saves nothing on a dry run', function () {
    Recipe::factory()->count(5)->create(['effort_tier' => EffortTier::Workhorse30]);

    [$status, $output] = plannedWith('--dry-run');

    expect($status)->toBe(0)
        ->and($output)->toContain('Dry run: nothing was saved.')
        ->and($output)->not->toContain('Saved.')
        ->and(Plan::count())->toBe(0)
        ->and(PlanNight::count())->toBe(0);
});

it('comes out the same way twice for the same seed', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);

    [, $first] = plannedWith('--dry-run --seed=2026');
    [, $again] = plannedWith('--dry-run --seed=2026');
    [, $different] = plannedWith('--dry-run --seed=1999');

    expect($again)->toBe($first)
        ->and($different)->not->toBe($first);
});

it('plans a named shopping day instead of the next one', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Workhorse30]);

    [$status, $output] = plannedWith('--shopping-date=2026-01-14');

    expect($status)->toBe(0)
        ->and($output)->toContain('Shopping Wed 14 Jan')
        ->and($output)->toContain('4 nights: Thu 15 Jan to Sun 18 Jan')
        ->and(Plan::sole()->shopping_date->toDateString())->toBe('2026-01-14');
});

it('leaves a planned window alone unless it is told to re-plan', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $before = $planner->planWindow(seed: 1)->nights->pluck('recipe_id')->all();

    plannedWith();

    expect(Plan::sole()->nights->pluck('recipe_id')->all())->toBe($before);

    plannedWith('--replan --seed=77');

    expect(Plan::sole()->nights->pluck('recipe_id')->all())->not->toBe($before);
});

it('keeps a locked night when it re-plans', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $locked = $planner->lockNight($planner->planWindow(seed: 1)->nights[1]);
    $lockedRecipe = $locked->recipe_id;

    plannedWith('--replan --seed=55');

    expect($locked->refresh()->recipe_id)->toBe($lockedRecipe)
        ->and($locked->is_locked)->toBeTrue();
});

it('marks the nights that depart from the normal week', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $planner->setOverride($planner->planWindow()->nights[0], cook: CookAssignment::Nobody, note: 'Out for dinner');

    [, $output] = plannedWith();

    expect($output)->toContain('Nobody *')
        ->and($output)->toContain('* Out for dinner');
});

it('fails with a readable message when the weekly schedule is incomplete', function () {
    CookScheduleNight::query()->where('weekday', Weekday::Sunday->value)->delete();
    Recipe::factory()->count(3)->create();

    [$status, $output] = plannedWith();

    expect($status)->toBe(1)
        ->and($output)->toContain('The weekly cook schedule has no Sunday')
        ->and(Plan::count())->toBe(0);
});

it('fails with a readable message on a date it cannot read', function () {
    [$status] = plannedWith('--shopping-date=not-a-date');

    expect($status)->toBe(1);
});
