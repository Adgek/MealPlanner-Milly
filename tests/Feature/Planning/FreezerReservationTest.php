<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Enums\Weekday;
use App\Models\FreezerItem;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use Illuminate\Support\Carbon;

/*
 * The freezer ledger, against the real database.
 *
 * A planned freezer meal spends one portion out of the stash, and that spend is
 * the only number the household counts (docs/decisions.md, "Freezer stash"). So
 * the arithmetic has to balance however the plan was written: through the
 * planner, by hand, or not at all. The reservation is recorded on the night
 * itself rather than inferred from "this night points at a freezer item", and a
 * release only hands a portion back when the night was really holding one.
 *
 * MealPool's in-memory ledger is unit-tested (tests/Unit); these are the
 * increments and decrements that actually reach freezer_items.portions_left.
 *
 * Time is frozen at Sunday 11 January 2026, so the next window is Mon 12 to Wed 14,
 * and only the Monday cooks: the count is then only ever about one night.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs([Weekday::Monday->value => [CookAssignment::Partner, EffortTier::Chaos15]], CookAssignment::Nobody);
});

afterEach(fn () => Carbon::setTestNow());

it('records the reservation on the night that holds it', function () {
    $soup = FreezerItem::factory()->portions(2)->create();
    Recipe::factory()->create(['cookable_by' => CookableBy::Partner, 'effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $night = $planner->chooseMeal($planner->planWindow()->nights[0], $soup);

    expect($night->selection)->toBe(NightSelection::FreezerItem)
        ->and($night->holds_freezer_portion)->toBeTrue()
        ->and($soup->refresh()->portions_left)->toBe(1);

    // Letting the night go gives the portion back, and the night stops claiming
    // to hold one.
    $rerolled = $planner->rerollNight($night);

    expect($soup->refresh()->portions_left)->toBe(2)
        ->and($rerolled->holds_freezer_portion)->toBeFalse();
});

it('invents no portion when it releases a night that is holding none', function () {
    // A night pointing at a freezer item that never went through the planner — a
    // factory, a seeder, an import — never took a portion out of the stash, so
    // releasing it must not put one in. This is also the state a night is left in
    // once it has been released, which is what makes releasing twice harmless.
    $soup = FreezerItem::factory()->portions(1)->create();
    $plan = Plan::factory()->window('2026-01-11')->create();
    $night = PlanNight::factory()->for($plan)->on('2026-01-12')->withFreezerItem($soup)->create([
        'cook' => CookAssignment::Partner,
        'time_budget' => EffortTier::Chaos15,
    ]);

    expect($night->holds_freezer_portion)->toBeFalse();

    // Nobody is cooking after all, so the night lets go of the meal it was
    // showing. There is nothing to hand back.
    $night = (new MealPlanner)->setOverride($night, cook: CookAssignment::Nobody, note: 'Eating out');

    expect($night->selection)->toBe(NightSelection::NightOff)
        ->and($soup->refresh()->portions_left)->toBe(1);
});

it('still holds the portion when a re-roll puts the same meal back', function () {
    // Nothing else fits, so the night keeps the freezer meal it had — and keeps
    // the reservation with it, rather than handing the portion back and eating
    // it anyway.
    $soup = FreezerItem::factory()->portions(2)->create();
    $planner = new MealPlanner;
    $night = $planner->chooseMeal($planner->planWindow()->nights[0], $soup);

    $kept = $planner->rerollNight($night);

    expect($kept->selection)->toBe(NightSelection::FreezerItem)
        ->and($kept->holds_freezer_portion)->toBeTrue()
        ->and($soup->refresh()->portions_left)->toBe(1);
});

it('hands a live reservation back when the plan is deleted', function () {
    $soup = FreezerItem::factory()->portions(1)->create();
    $planner = new MealPlanner;
    $plan = $planner->planWindow();
    $planner->chooseMeal($plan->nights[0], $soup);

    expect($soup->refresh()->portions_left)->toBe(0);

    $plan->delete();

    expect($soup->refresh()->portions_left)->toBe(1)
        ->and(PlanNight::count())->toBe(0);
});

it('invents no portion when a deleted plan was holding none', function () {
    $soup = FreezerItem::factory()->portions(1)->create();
    $plan = Plan::factory()->window('2026-01-11')->create();
    PlanNight::factory()->for($plan)->on('2026-01-12')->withFreezerItem($soup)->create();

    $plan->delete();

    expect($soup->refresh()->portions_left)->toBe(1);
});
