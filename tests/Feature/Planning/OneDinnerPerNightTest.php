<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\FreezerItem;
use App\Models\MealHistoryEntry;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;

/*
 * One dinner per night, across every plan there is.
 *
 * Changing the shopping days is a first-class MM-4 action, and it moves where the
 * windows fall. The plan that is already out was built for the old days, so its
 * nights can overlap the window being planned now — and `plan_nights.date` is
 * what MM-9's daily reminder and MM-14's dashboard ask "what are we eating
 * tonight", with no tiebreak if two rows answer.
 *
 * So the window being planned owns its nights: any other plan's claim on those
 * dates is given up, and whatever it was holding goes back in the freezer.
 *
 * Time is frozen at Sunday 11 January 2026.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Move the household's shopping days, the way the Settings page does.
 */
function shoppingDaysAre(Weekday ...$weekdays): void
{
    HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, array_map(
        fn (Weekday $weekday) => ['weekday' => $weekday->value, 'time' => '17:30'],
        $weekdays,
    ));
}

it('leaves one dinner on a night the new shopping days moved into another window', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;

    // Sunday's shop covers Mon 12 to Wed 14.
    $planner->planWindow(seed: 1);

    // The household moves shopping to Tuesday and Friday. The next shop is now
    // Tuesday the 13th, covering Wed 14 to Fri 16 — and Wednesday the 14th is
    // already on Sunday's plan.
    shoppingDaysAre(Weekday::Tuesday, Weekday::Friday);
    $planner->planWindow(seed: 2);

    expect(PlanNight::where('date', '2026-01-14')->count())->toBe(1)
        ->and(PlanNight::where('date', '2026-01-14')->sole()->plan->shopping_date->toDateString())
        ->toBe('2026-01-13');
});

it('keeps the nights the old plan is still feeding', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $sunday = $planner->planWindow(seed: 1);
    $monday = $sunday->nights[0]->recipe_id;

    shoppingDaysAre(Weekday::Tuesday, Weekday::Friday);
    $planner->planWindow(seed: 2);

    // Monday and Tuesday were shopped for on Sunday and are being cooked; only
    // the overlap is given up, and the plan's own window shrinks to match.
    $sunday->refresh();

    expect($sunday->nights->map->date->map->toDateString()->all())->toBe(['2026-01-12', '2026-01-13'])
        ->and($sunday->nights[0]->recipe_id)->toBe($monday)
        ->and($sunday->last_night->toDateString())->toBe('2026-01-13');
});

it('gives every night exactly one dinner, across every plan there is', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $planner->planWindow(seed: 1);

    shoppingDaysAre(Weekday::Tuesday, Weekday::Friday);
    $planner->planWindow(seed: 2);

    $dates = PlanNight::query()->orderBy('date')->pluck('date')->map->toDateString();

    // What MM-9's reminder and MM-14's dashboard ask for: one row per date, so
    // "what are we eating tonight" has one answer and needs no tiebreak.
    expect($dates->unique()->count())->toBe($dates->count())
        ->and($dates->all())->toBe(['2026-01-12', '2026-01-13', '2026-01-14', '2026-01-15', '2026-01-16']);
});

it('puts the freezer portion back when a night is handed over', function () {
    theWeekIs([Weekday::Wednesday->value => [CookAssignment::Partner, EffortTier::Chaos15]], CookAssignment::Nobody);
    $soup = FreezerItem::factory()->portions(1)->create();
    $planner = new MealPlanner;
    $wednesday = $planner->planWindow()->nights[2];

    expect($wednesday->freezer_item_id)->toBe($soup->id)
        ->and($soup->refresh()->portions_left)->toBe(0);

    // Wednesday now belongs to Tuesday's shop. The portion Sunday's plan was
    // holding has to go back, or it is lost for good.
    shoppingDaysAre(Weekday::Tuesday, Weekday::Friday);
    $planner->planWindow();

    expect(PlanNight::where('date', '2026-01-14')->count())->toBe(1)
        ->and($soup->refresh()->portions_left)->toBe(0)
        ->and(PlanNight::where('date', '2026-01-14')->sole()->freezer_item_id)->toBe($soup->id);
});

it('drops a plan left with no nights at all', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $planner->planWindow(seed: 1);

    expect(Plan::count())->toBe(1);

    // An unplanned Saturday shop, with the shopping days now Saturday and
    // Thursday: its window runs Sun 11 to Thu 15 and swallows every night Sunday's
    // plan was for. A plan with no nights is not a plan.
    shoppingDaysAre(Weekday::Saturday, Weekday::Thursday);
    $planner->planWindow($planner->windowFor(aDate('2026-01-10')), seed: 2);

    expect(Plan::count())->toBe(1)
        ->and(Plan::sole()->shopping_date->toDateString())->toBe('2026-01-10')
        ->and(Plan::sole()->nights->map->date->map->toDateString()->all())
        ->toBe(['2026-01-11', '2026-01-12', '2026-01-13', '2026-01-14', '2026-01-15']);
});

it('writes one history row for a night two plans used to claim', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $planner->planWindow(seed: 1);

    shoppingDaysAre(Weekday::Tuesday, Weekday::Friday);
    $planner->planWindow(seed: 2);

    Carbon::setTestNow(Carbon::parse('2026-01-20 08:00:00', 'America/Toronto'));
    $planner->recordPastNightsAsCooked();

    expect(MealHistoryEntry::where('cooked_on', '2026-01-14')->count())->toBe(1);
});

it('leaves another household plan alone when the windows do not overlap', function () {
    Recipe::factory()->count(8)->create([
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Workhorse30,
    ]);
    $planner = new MealPlanner;
    $sunday = $planner->planWindow(seed: 1);

    // Wednesday's shop covers Thu 15 to Sun 18: no night is shared.
    $planner->planWindow($planner->windowFor(aDate('2026-01-14')), seed: 2);

    expect($sunday->refresh()->nights)->toHaveCount(3)
        ->and(Plan::count())->toBe(2)
        ->and(PlanNight::count())->toBe(7);
});
