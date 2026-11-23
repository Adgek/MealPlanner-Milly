<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Enums\Weekday;
use App\Models\FreezerItem;
use App\Models\MealHistoryEntry;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\Exceptions\PlanningException;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
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
    $nights = $planner->planWindow($window, seed: 99, replan: true)->nights;

    expect($nights->pluck('recipe_id', 'date')->all())->toBe($before)
        // Not just the dinner: the whole row is the record of that night.
        ->and($nights->pluck('pick_note')->filter())->toBeEmpty()
        ->and($nights->every(fn (PlanNight $night) => $night->selection === NightSelection::Recipe))
        ->toBeTrue();
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

it('reads "now" on the kitchen clock when it catches meal history up', function () {
    // 8pm on the 14th in Toronto is already 1am on the 15th in UTC. Tonight's
    // dinner has not been eaten yet, so it is not history — whether the caller
    // passes the instant or lets the planner read the clock.
    $recipe = Recipe::factory()->create();
    $plan = Plan::factory()->window('2026-01-13')->create();
    PlanNight::factory()->for($plan)->on('2026-01-14')->withRecipe($recipe)->create();
    Carbon::setTestNow(Carbon::parse('2026-01-14 20:00:00', 'America/Toronto'));
    $planner = new MealPlanner;

    expect($planner->recordPastNightsAsCooked(Carbon::now()))->toBe(0)
        ->and($planner->recordPastNightsAsCooked())->toBe(0)
        ->and(MealHistoryEntry::count())->toBe(0);

    // The next morning it is history, either way round.
    Carbon::setTestNow(Carbon::parse('2026-01-15 07:00:00', 'America/Toronto'));

    expect($planner->recordPastNightsAsCooked(Carbon::now()))->toBe(1)
        ->and(MealHistoryEntry::sole()->cooked_on->toDateString())->toBe('2026-01-14');
});

it('reads the instant it is given as the night the household is living in', function () {
    // Midnight UTC on the 14th is still the evening of the 13th in Toronto, so
    // the 13th's dinner is on the table rather than in the past. Only the 12th is
    // history. Every other "now" argument on the planner converts the same way.
    $recipe = Recipe::factory()->create();
    $plan = Plan::factory()->window('2026-01-11')->create();
    PlanNight::factory()->for($plan)->on('2026-01-12')->withRecipe($recipe)->create();
    PlanNight::factory()->for($plan)->on('2026-01-13')->withRecipe($recipe)->create();
    PlanNight::factory()->for($plan)->on('2026-01-14')->withRecipe($recipe)->create();

    $recorded = (new MealPlanner)->recordPastNightsAsCooked(Carbon::parse('2026-01-14 00:30:00', 'UTC'));

    expect($recorded)->toBe(1)
        ->and(MealHistoryEntry::sole()->cooked_on->toDateString())->toBe('2026-01-12');
});

it('invents no night for a date that has already gone by', function () {
    // currentWindow() is the documented way to ask "the window tonight's dinner
    // belongs to", and it begins in the past. Planning it must not mint a second
    // row for a night another plan is already the record of: MM-9's reminder and
    // MM-14's dashboard read plan_nights.date and expect one answer.
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    // Wednesday's shop, covering Thu 8 to Sun 11.
    Carbon::setTestNow(Carbon::parse('2026-01-07 09:00:00', 'America/Toronto'));
    $planner->planWindow($planner->windowFor(aDate('2026-01-07')));

    // Saturday: that is still the window in force, and two of its nights have
    // gone by. The household moves shopping to Thursday and Sunday.
    Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00', 'America/Toronto'));
    HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, [
        ['weekday' => Weekday::Thursday->value, 'time' => '17:30'],
        ['weekday' => Weekday::Sunday->value, 'time' => '10:00'],
    ]);

    $planner->planWindow($planner->currentWindow());

    $dates = PlanNight::query()->orderBy('date')->pluck('date')->map->toDateString();

    expect($dates->unique()->count())->toBe($dates->count())
        ->and($dates->all())->toBe(['2026-01-08', '2026-01-09', '2026-01-10', '2026-01-11']);
});

it('leaves who cooked a past night as it was recorded', function () {
    // The plan is the record of what happened on a night that has gone by.
    // Rewriting its cook from the week as it is now is falsifying that record —
    // and the night's dinner was chosen for the cook it had.
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $window = $planner->windowFor(aDate('2026-01-11'));
    $planner->planWindow($window, seed: 1);

    Carbon::setTestNow(Carbon::parse('2026-01-18 08:00:00', 'America/Toronto'));
    theWeekIs([], CookAssignment::Me, EffortTier::Project60);
    $nights = $planner->planWindow($window, seed: 99, replan: true)->nights;

    expect($nights->every(fn (PlanNight $night) => $night->cook === CookAssignment::Together))->toBeTrue()
        ->and($nights->every(fn (PlanNight $night) => $night->time_budget === EffortTier::Workhorse30))
        ->toBeTrue();
});

it('keeps a past night the window no longer covers', function () {
    // Wednesday happened and was cooked. A shorter window does not un-happen it,
    // and the plan is the only record that it did.
    Recipe::factory()->count(8)->create(['effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $planner->planWindow(seed: 1);

    Carbon::setTestNow(Carbon::parse('2026-01-16 08:00:00', 'America/Toronto'));
    HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, [
        ['weekday' => Weekday::Sunday->value, 'time' => '10:00'],
        ['weekday' => Weekday::Tuesday->value, 'time' => '17:30'],
    ]);

    // Sunday's window is now Mon 12 to Tue 13, so Wednesday falls outside it.
    $planner->planWindow($planner->windowFor(aDate('2026-01-11')));

    expect(PlanNight::query()->orderBy('date')->pluck('date')->map->toDateString()->all())
        ->toBe(['2026-01-12', '2026-01-13', '2026-01-14']);
});
