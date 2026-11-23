<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\FreezerItem;
use App\Models\MealHistoryEntry;
use App\Models\Recipe;
use App\Planning\Exceptions\PlanningException;
use App\Planning\MealPlanner;
use Illuminate\Support\Carbon;

/*
 * The fence around a night that has gone by.
 *
 * "Until you shop, you can re-roll, lock or swap any night" (docs/decisions.md):
 * once a night has passed, what was on it is what the household ate, and the
 * planner has no business changing it. Nothing enforced that, so a past night
 * could be re-rolled — handing back a freezer portion that was eaten two days
 * ago — and a re-plan rolled every unlocked night of a finished window, which
 * then wrote meal history for dinners nobody cooked and suppressed those recipes
 * for three weeks on the strength of it.
 *
 * Tonight is not the past: a night is fenced off only once its date is behind
 * today on the kitchen clock.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

it('refuses to re-roll a night that has already gone by', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $monday = $planner->planWindow(seed: 1)->nights[0];
    $dinner = $monday->recipe_id;

    // Wednesday: Monday's dinner was eaten two days ago.
    Carbon::setTestNow(Carbon::parse('2026-01-14 08:00:00', 'America/Toronto'));

    expect(fn () => $planner->rerollNight($monday))
        ->toThrow(PlanningException::class, 'has already gone by');

    expect($monday->refresh()->recipe_id)->toBe($dinner);
});

it('keeps the freezer count when a past night is re-rolled', function () {
    theWeekIs([Weekday::Monday->value => [CookAssignment::Partner, EffortTier::Chaos15]], CookAssignment::Nobody);
    $soup = FreezerItem::factory()->portions(1)->create();
    Recipe::factory()->count(3)->create(['cookable_by' => CookableBy::Partner, 'effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $monday = $planner->chooseMeal($planner->planWindow()->nights[0], $soup);

    expect($soup->refresh()->portions_left)->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-01-14 08:00:00', 'America/Toronto'));

    expect(fn () => $planner->rerollNight($monday->refresh()))->toThrow(PlanningException::class);

    // The soup was eaten on Monday. Putting the portion back would tell MM-12's
    // low-stash warning there is soup in the freezer that is long gone.
    expect($soup->refresh()->portions_left)->toBe(0);
});

it('refuses to swap the meal on a night that has already gone by', function () {
    Recipe::factory()->count(3)->create(['effort_tier' => EffortTier::Workhorse30]);
    $chili = Recipe::factory()->create(['name' => 'Chili', 'effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $monday = $planner->planWindow(seed: 1)->nights[0];
    $dinner = $monday->recipe_id;

    Carbon::setTestNow(Carbon::parse('2026-01-14 08:00:00', 'America/Toronto'));

    expect(fn () => $planner->chooseMeal($monday, $chili))
        ->toThrow(PlanningException::class, 'has already gone by');

    expect($monday->refresh()->recipe_id)->toBe($dinner);
});

it('still lets tonight be re-rolled', function () {
    // The fence is about nights that have gone, not the one being cooked: at
    // 8pm the household can still decide on something else.
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $monday = $planner->planWindow(seed: 1)->nights[0];
    $dinner = $monday->recipe_id;

    Carbon::setTestNow(Carbon::parse('2026-01-12 20:00:00', 'America/Toronto'));

    expect($planner->rerollNight($monday, seed: 7)->recipe_id)->not->toBe($dinner);
});

it('leaves the nights it has already fed alone when it re-plans', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $window = $planner->windowFor(aDate('2026-01-11'));
    $before = $planner->planWindow($window, seed: 1)->nights->pluck('recipe_id', 'date')->all();

    // The window is over, and somebody re-plans it with another seed — a
    // --shopping-date away.
    Carbon::setTestNow(Carbon::parse('2026-01-18 08:00:00', 'America/Toronto'));
    $after = $planner->planWindow($window, seed: 99, replan: true)->nights->pluck('recipe_id', 'date')->all();

    expect($after)->toBe($before);
});

it('writes one history row per dinner, however often the window is re-planned', function () {
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $window = $planner->windowFor(aDate('2026-01-11'));
    $planner->planWindow($window, seed: 1);

    Carbon::setTestNow(Carbon::parse('2026-01-18 08:00:00', 'America/Toronto'));
    $planner->planWindow($window, seed: 99, replan: true);
    $planner->recordPastNightsAsCooked();

    // Three dinners were cooked, so history has three rows. Six would suppress
    // three recipes the household never ate for the whole lookback.
    expect(MealHistoryEntry::count())->toBe(3)
        ->and(MealHistoryEntry::pluck('cooked_on')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-01-12', '2026-01-13', '2026-01-14']);
});

it('still fills a night that is yet to come when it re-plans a part-finished window', function () {
    // Wednesday is still ahead of us: the fence must not freeze the whole plan.
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $window = $planner->windowFor(aDate('2026-01-11'));
    $before = $planner->planWindow($window, seed: 1)->nights->pluck('recipe_id', 'date')->all();

    Carbon::setTestNow(Carbon::parse('2026-01-13 09:00:00', 'America/Toronto'));
    $nights = $planner->planWindow($window, seed: 99, replan: true)->nights;
    $after = $nights->pluck('recipe_id', 'date')->all();

    expect(array_slice($after, 0, 1))->toBe(array_slice($before, 0, 1))
        ->and(array_slice($after, 1))->not->toBe(array_slice($before, 1))
        ->and($nights->pluck('recipe_id')->unique())->toHaveCount(3);
});
