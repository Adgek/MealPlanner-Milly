<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Enums\Weekday;
use App\Models\CookScheduleNight;
use App\Models\FreezerItem;
use App\Models\MealHistoryEntry;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\Exceptions\PlanningException;
use App\Planning\MealPlanner;
use App\Planning\MealPool;
use App\Planning\PlanningRules;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;

/*
 * The planner against the real database: the five functions other tickets call,
 * the plan it saves, the freezer count it keeps honest and the meal history it
 * writes. The rules themselves are unit-tested without a database (tests/Unit).
 *
 * Time is frozen at Sunday 11 January 2026, a shopping day, so "the next window"
 * is Monday the 12th to Wednesday the 14th throughout.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

it('plans the window the next shop is for', function () {
    Recipe::factory()->count(5)->create(['effort_tier' => EffortTier::Workhorse30]);

    $plan = (new MealPlanner)->planWindow();

    expect($plan->shopping_date->toDateString())->toBe('2026-01-11')
        ->and($plan->first_night->toDateString())->toBe('2026-01-12')
        ->and($plan->last_night->toDateString())->toBe('2026-01-14')
        ->and($plan->nights)->toHaveCount(3)
        ->and($plan->nights->map->date->map->toDateString()->all())
        ->toBe(['2026-01-12', '2026-01-13', '2026-01-14'])
        ->and($plan->nights->every(fn (PlanNight $night) => $night->selection === NightSelection::Recipe))
        ->toBeTrue();
});

it('copies who cooks and how long from the weekly schedule', function () {
    theWeekIs([
        Weekday::Monday->value => [CookAssignment::Me, EffortTier::Project60],
        Weekday::Tuesday->value => [CookAssignment::Partner, EffortTier::Chaos15],
        Weekday::Wednesday->value => [CookAssignment::Nobody, EffortTier::Chaos15],
    ]);
    Recipe::factory()->count(4)->create(['effort_tier' => EffortTier::Chaos15]);

    $nights = (new MealPlanner)->planWindow()->nights;

    expect($nights[0]->cook)->toBe(CookAssignment::Me)
        ->and($nights[0]->time_budget)->toBe(EffortTier::Project60)
        ->and($nights[1]->cook)->toBe(CookAssignment::Partner)
        ->and($nights[2]->cook)->toBe(CookAssignment::Nobody)
        // Nobody cooking means no recipe: leftovers or eating out.
        ->and($nights[2]->selection)->toBe(NightSelection::NightOff)
        ->and($nights[2]->recipe_id)->toBeNull()
        ->and($nights->every(fn (PlanNight $night) => $night->overrides_schedule === false))->toBeTrue();
});

it('plans one window once, however often it is asked', function () {
    Recipe::factory()->count(4)->create();
    $planner = new MealPlanner;

    $first = $planner->planWindow();
    $chosen = $first->nights->map->recipe_id->all();

    $second = $planner->planWindow();

    expect(Plan::count())->toBe(1)
        ->and(PlanNight::count())->toBe(3)
        // Asked again it fills in what is open and leaves the rest alone.
        ->and($second->nights->map->recipe_id->all())->toBe($chosen);
});

it('uses a recipe once across the window and favours what we have not had', function () {
    $recipes = Recipe::factory()->count(3)->create(['effort_tier' => EffortTier::Workhorse30]);

    $nights = (new MealPlanner)->planWindow(seed: 1)->nights;

    expect($nights->pluck('recipe_id')->unique())->toHaveCount(3)
        ->and($nights->pluck('recipe_id')->sort()->values()->all())
        ->toBe($recipes->pluck('id')->sort()->values()->all());
});

it('reads the vault and meal history into the pool it picks from', function () {
    $bulgogi = Recipe::factory()->create(['name' => 'Beef Bulgogi']);
    MealHistoryEntry::factory()->for($bulgogi)->on('2026-01-04')->create();
    MealHistoryEntry::factory()->for($bulgogi)->on('2025-12-20')->create();
    Recipe::factory()->create(['name' => 'Chili']);
    FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);
    FreezerItem::factory()->empty()->create(['name' => 'Frozen nothing']);

    $pool = MealPool::fromVault();

    expect($pool->recipes())->toHaveCount(2)
        // The most recent time we cooked it, not the first.
        ->and($pool->option('recipe:'.$bulgogi->id)->lastCookedOn()->toDateString())->toBe('2026-01-04')
        ->and($pool->option('recipe:'.Recipe::firstWhere('name', 'Chili')->id)->lastCookedOn())->toBeNull()
        // An empty box is no use to a plan.
        ->and($pool->freezerMeals())->toHaveCount(1)
        ->and($pool->freezerMeals()[0]->name)->toBe('Frozen minestrone');
});

it('keeps a locked night when the window is planned again', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;

    $plan = $planner->planWindow(seed: 1);
    $locked = $planner->lockNight($plan->nights[1]);
    $lockedRecipe = $locked->recipe_id;
    $before = $plan->nights->pluck('recipe_id', 'id');

    $replanned = $planner->planWindow(seed: 99, replan: true);

    expect($replanned->nights[1]->recipe_id)->toBe($lockedRecipe)
        ->and($replanned->nights[1]->is_locked)->toBeTrue()
        // ...and the rest really were rolled again.
        ->and($replanned->nights->pluck('recipe_id', 'id')->all())->not->toBe($before->all())
        // The locked meal is not handed to another night of the same window.
        ->and($replanned->nights->pluck('recipe_id')->unique())->toHaveCount(3);
});

it('keeps a one-off override when the window is planned again', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;

    $plan = $planner->planWindow(seed: 1);
    $planner->setOverride($plan->nights[0], cook: CookAssignment::Nobody, note: 'Out for dinner');

    $replanned = $planner->planWindow(seed: 2, replan: true);

    expect($replanned->nights[0]->cook)->toBe(CookAssignment::Nobody)
        ->and($replanned->nights[0]->overrides_schedule)->toBeTrue()
        ->and($replanned->nights[0]->override_note)->toBe('Out for dinner')
        ->and($replanned->nights[0]->selection)->toBe(NightSelection::NightOff);
});

it('picks up a change to the weekly schedule for a night nobody has touched', function () {
    // Only the Monday cooks, so the window has one night in play.
    theWeekIs([Weekday::Monday->value => [CookAssignment::Me, EffortTier::Project60]], CookAssignment::Nobody);
    Recipe::factory()->create(['name' => 'Chili', 'effort_tier' => EffortTier::Project60, 'cookable_by' => CookableBy::Me]);
    $planner = new MealPlanner;

    $plan = $planner->planWindow();
    expect($plan->nights[0]->recipe->name)->toBe('Chili');

    // The household edits its normal week: Monday is now a 15-minute night.
    Recipe::factory()->create(['name' => 'Quick pasta', 'effort_tier' => EffortTier::Chaos15, 'cookable_by' => CookableBy::Both]);
    theWeekIs([Weekday::Monday->value => [CookAssignment::Me, EffortTier::Chaos15]], CookAssignment::Nobody);
    $plan = $planner->planWindow();

    expect($plan->nights[0]->time_budget)->toBe(EffortTier::Chaos15)
        // The hour-long project no longer fits, so the night was rolled again.
        ->and($plan->nights[0]->recipe->name)->toBe('Quick pasta');
});

it('drops the nights a shorter window no longer covers', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $planner->planWindow();

    // The household adds a Tuesday shop, so Sunday's window now ends on Tuesday
    // and Wednesday belongs to the next one.
    HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, [
        ['weekday' => Weekday::Sunday->value, 'time' => '10:00'],
        ['weekday' => Weekday::Tuesday->value, 'time' => '17:30'],
        ['weekday' => Weekday::Wednesday->value, 'time' => '17:30'],
    ]);

    $plan = $planner->planWindow();

    expect($plan->nights)->toHaveCount(2)
        ->and($plan->last_night->toDateString())->toBe('2026-01-13')
        ->and(PlanNight::count())->toBe(2);
});

it('marks a night nothing fits, with the reason, and plans the rest', function () {
    theWeekIs([Weekday::Tuesday->value => [CookAssignment::Partner, EffortTier::Project60]]);
    // Nothing my partner would make on her own.
    Recipe::factory()->count(3)->create([
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Workhorse30,
    ]);

    $planner = new MealPlanner;
    $plan = $planner->planWindow();
    $nights = $plan->nights;
    $reason = $planner->picksFor($plan)[1]->explain(HouseholdSettings::cookLabel(...));

    expect($nights[1]->selection)->toBe(NightSelection::NothingFits)
        ->and($nights[1]->nothingFits())->toBeTrue()
        ->and($nights[1]->hasMeal())->toBeFalse()
        // The reason is stored as counts and read back in the household's words,
        // so renaming a cook renames them in a reason written months ago (MM-4).
        ->and($nights[1]->pick_reason['ruled_out_by_cook'])->toBe(3)
        ->and($reason)->toContain('not tagged for Partner')
        ->and($reason)->toContain('a solo night is capped at 15-min chaos')
        // The other two nights are fine.
        ->and($nights[0]->hasMeal())->toBeTrue()
        ->and($nights[2]->hasMeal())->toBeTrue();
});

it('spends a freezer portion when it plans a freezer meal', function () {
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    // The only thing in the house she would make on her own.
    $soup = FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);

    $nights = (new MealPlanner)->planWindow()->nights;

    expect($nights[0]->selection)->toBe(NightSelection::FreezerItem)
        ->and($nights[0]->freezerItem->name)->toBe('Frozen minestrone')
        ->and($soup->refresh()->portions_left)->toBe(1)
        // Two portions in the box, but a meal is not repeated inside a window.
        ->and($nights[1]->nothingFits())->toBeTrue();
});

it('puts the portion back when the freezer night is rolled again', function () {
    // One night in play, so the count is only ever about this night.
    theWeekIs([Weekday::Monday->value => [CookAssignment::Partner, EffortTier::Chaos15]], CookAssignment::Nobody);
    $soup = FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);
    Recipe::factory()->count(4)->create([
        'cookable_by' => CookableBy::Partner,
        'effort_tier' => EffortTier::Chaos15,
    ]);
    $planner = new MealPlanner;
    $night = $planner->chooseMeal($planner->planWindow()->nights[0], $soup);

    expect($soup->refresh()->portions_left)->toBe(1);

    $rerolled = $planner->rerollNight($night);

    expect($soup->refresh()->portions_left)->toBe(2)
        ->and($rerolled->freezer_item_id)->toBeNull()
        ->and($rerolled->selection)->toBe(NightSelection::Recipe);
});

it('never plans a freezer meal that has no portions left', function () {
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    FreezerItem::factory()->empty()->create();

    $planner = new MealPlanner;
    $plan = $planner->planWindow();

    expect($plan->nights->every(fn (PlanNight $night) => $night->nothingFits()))->toBeTrue()
        // A box with nothing in it is an empty stash, as far as a plan is concerned.
        ->and($planner->picksFor($plan)[0]->explain(HouseholdSettings::cookLabel(...)))
        ->toContain('the freezer stash is empty');
});

it('rolls one night again without touching the others', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $plan = $planner->planWindow(seed: 1);
    $before = $plan->nights->pluck('recipe_id', 'date')->all();

    $rerolled = $planner->rerollNight($plan->nights[1], seed: 7);
    $after = $plan->refresh()->nights->pluck('recipe_id', 'date')->all();

    expect($rerolled->recipe_id)->not->toBe($before[array_keys($before)[1]])
        ->and($after[array_keys($after)[0]])->toBe($before[array_keys($before)[0]])
        ->and($after[array_keys($after)[2]])->toBe($before[array_keys($before)[2]])
        // Still no repeats across the window.
        ->and(array_unique(array_values($after)))->toHaveCount(3);
});

it('refuses to roll a locked night again', function () {
    Recipe::factory()->count(4)->create();
    $planner = new MealPlanner;
    $plan = $planner->planWindow();
    $locked = $planner->lockNight($plan->nights[0]);

    expect(fn () => $planner->rerollNight($locked))
        ->toThrow(PlanningException::class, 'is locked');

    expect($locked->refresh()->recipe_id)->not->toBeNull();
});

it('keeps the dinner it has when a re-roll finds nothing else', function () {
    // One recipe in the vault: a re-roll has nowhere to go.
    $only = Recipe::factory()->create(['name' => 'Chili', 'effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;
    $plan = $planner->planWindow();
    $night = $plan->nights[0];

    $rerolled = $planner->rerollNight($night);

    expect($rerolled->recipe_id)->toBe($only->id)
        ->and($rerolled->selection)->toBe(NightSelection::Recipe)
        ->and($rerolled->pick_note)->toStartWith('Kept Chili: nothing else fits.');
});

it('locks and unlocks a night', function () {
    Recipe::factory()->count(4)->create();
    $planner = new MealPlanner;
    $night = $planner->planWindow()->nights[0];

    expect($planner->lockNight($night)->fresh()->is_locked)->toBeTrue()
        ->and($planner->unlockNight($night)->fresh()->is_locked)->toBeFalse()
        ->and($night->isRerollable())->toBeTrue();
});

it('puts a chosen recipe on a night, rules or no rules', function () {
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    Recipe::factory()->create(['cookable_by' => CookableBy::Partner, 'effort_tier' => EffortTier::Chaos15]);
    // The household's own choice: a project on my partner's solo 15-minute night.
    $project = Recipe::factory()->create([
        'name' => 'Chili',
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Project60,
    ]);
    $planner = new MealPlanner;
    $night = $planner->planWindow()->nights[0];

    $chosen = $planner->chooseMeal($night, $project);

    expect($chosen->recipe_id)->toBe($project->id)
        ->and($chosen->selection)->toBe(NightSelection::Recipe)
        ->and($chosen->is_locked)->toBeFalse()
        ->and($chosen->pick_note)->toBeNull();
});

it('can lock a night as it chooses a meal for it', function () {
    Recipe::factory()->count(3)->create();
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    $planner = new MealPlanner;
    $plan = $planner->planWindow();

    $planner->chooseMeal($plan->nights[0], $chili, lock: true);
    $replanned = $planner->planWindow(seed: 5, replan: true);

    expect($replanned->nights[0]->recipe_id)->toBe($chili->id)
        ->and($replanned->nights[0]->is_locked)->toBeTrue();
});

it('moves the freezer count when a chosen meal replaces another', function () {
    theWeekIs([Weekday::Monday->value => [CookAssignment::Partner, EffortTier::Chaos15]], CookAssignment::Nobody);
    $minestrone = FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);
    $chili = FreezerItem::factory()->portions(1)->create(['name' => 'Frozen chili']);
    $planner = new MealPlanner;
    $night = $planner->chooseMeal($planner->planWindow()->nights[0], $minestrone);

    expect($minestrone->refresh()->portions_left)->toBe(1);

    $planner->chooseMeal($night, $chili);

    expect($minestrone->refresh()->portions_left)->toBe(2)
        ->and($chili->refresh()->portions_left)->toBe(0)
        ->and($night->refresh()->freezer_item_id)->toBe($chili->id);
});

it('refuses a freezer meal with nothing left in the box', function () {
    Recipe::factory()->count(3)->create();
    $empty = FreezerItem::factory()->empty()->create(['name' => 'Frozen minestrone']);
    $planner = new MealPlanner;
    $night = $planner->planWindow()->nights[0];

    expect(fn () => $planner->chooseMeal($night, $empty))
        ->toThrow(PlanningException::class, 'no portions of [Frozen minestrone] left');
});

it('sets a one-off override for a single date', function () {
    Recipe::factory()->count(4)->create(['effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $plan = $planner->planWindow();

    $night = $planner->setOverride(
        $plan->nights[1],
        cook: CookAssignment::Partner,
        timeBudget: EffortTier::Chaos15,
        note: 'I am travelling',
    );

    expect($night->overrides_schedule)->toBeTrue()
        ->and($night->cook)->toBe(CookAssignment::Partner)
        ->and($night->override_note)->toBe('I am travelling')
        // The normal week is untouched.
        ->and(CookScheduleNight::forWeekday(Weekday::Tuesday)->cook)
        ->toBe(CookAssignment::Together);
});

it('keeps the dinner an override does not disturb', function () {
    Recipe::factory()->count(4)->create([
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Chaos15,
    ]);
    $planner = new MealPlanner;
    $plan = $planner->planWindow();
    $before = $plan->nights[0]->recipe_id;

    // Still cookable, still enough time: nothing to re-roll.
    $night = $planner->setOverride($plan->nights[0], note: 'Kids are at their grandparents');

    expect($night->recipe_id)->toBe($before)
        ->and($night->override_note)->toBe('Kids are at their grandparents');
});

it('rolls a night again when an override leaves its dinner impossible', function () {
    Recipe::factory()->create(['name' => 'Chili', 'cookable_by' => CookableBy::Me, 'effort_tier' => EffortTier::Project60]);
    Recipe::factory()->create(['name' => 'Quick pasta', 'cookable_by' => CookableBy::Partner, 'effort_tier' => EffortTier::Chaos15]);
    theWeekIs([], CookAssignment::Me, EffortTier::Project60);
    $planner = new MealPlanner;
    $plan = $planner->planWindow();

    expect($plan->nights[0]->recipe->name)->toBe('Chili');

    // I am away: my partner is on her own, so the project is out.
    $night = $planner->setOverride($plan->nights[0], cook: CookAssignment::Partner, note: 'I am travelling');

    expect($night->refresh()->recipe->name)->toBe('Quick pasta');
});

it('clears a night and its freezer portion when nobody is cooking', function () {
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    $soup = FreezerItem::factory()->portions(1)->create();
    $planner = new MealPlanner;
    $night = $planner->chooseMeal($planner->planWindow()->nights[0], $soup);

    expect($soup->refresh()->portions_left)->toBe(0);

    $night = $planner->setOverride($night, cook: CookAssignment::Nobody, note: 'Eating out');

    expect($night->selection)->toBe(NightSelection::NightOff)
        ->and($night->freezer_item_id)->toBeNull()
        ->and($soup->refresh()->portions_left)->toBe(1);
});

it('leaves a locked night alone when its date is overridden', function () {
    // A lock outranks the picking rules: the household wants this dinner on this
    // night, and an override that still leaves somebody cooking does not take it
    // away — even though my partner on her own would never be handed the project.
    // The one override that does is one that leaves nobody cooking, because then
    // there is no dinner to keep ('clears a locked night when nobody is cooking
    // after all').
    Recipe::factory()->count(4)->create(['cookable_by' => CookableBy::Me, 'effort_tier' => EffortTier::Project60]);
    theWeekIs([], CookAssignment::Me, EffortTier::Project60);
    $planner = new MealPlanner;
    $night = $planner->lockNight($planner->planWindow()->nights[0]);
    $locked = $night->recipe_id;

    $night = $planner->setOverride($night, cook: CookAssignment::Partner, note: 'I am travelling');

    expect($night->cook)->toBe(CookAssignment::Partner)
        ->and($night->recipe_id)->toBe($locked)
        ->and($night->is_locked)->toBeTrue();
});

it('puts a night back on the normal week', function () {
    theWeekIs([], CookAssignment::Me, EffortTier::Project60);
    Recipe::factory()->create(['name' => 'Chili', 'cookable_by' => CookableBy::Me, 'effort_tier' => EffortTier::Project60]);
    Recipe::factory()->create(['name' => 'Quick pasta', 'cookable_by' => CookableBy::Partner, 'effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;
    $night = $planner->setOverride(
        $planner->planWindow()->nights[0],
        cook: CookAssignment::Partner,
        timeBudget: EffortTier::Chaos15,
        note: 'I am travelling',
    );

    expect($night->refresh()->recipe->name)->toBe('Quick pasta');

    $night = $planner->clearOverride($night);

    expect($night->overrides_schedule)->toBeFalse()
        ->and($night->override_note)->toBeNull()
        ->and($night->cook)->toBe(CookAssignment::Me)
        ->and($night->time_budget)->toBe(EffortTier::Project60)
        ->and($night->refresh()->recipe->name)->toBe('Chili');
});

it('changes nothing when an override is set with nothing in it', function () {
    Recipe::factory()->count(4)->create();
    $planner = new MealPlanner;
    $night = $planner->planWindow()->nights[0];

    $planner->setOverride($night);

    expect($night->refresh()->overrides_schedule)->toBeFalse();
});

it('records the nights that have passed in meal history', function () {
    $recipe = Recipe::factory()->create();
    $plan = Plan::factory()->window('2026-01-07')->create();
    // Two nights behind us, one still ahead.
    PlanNight::factory()->for($plan)->on('2026-01-08')->withRecipe($recipe)->create();
    PlanNight::factory()->for($plan)->on('2026-01-09')->withRecipe($recipe)->create();
    PlanNight::factory()->for($plan)->on('2026-01-13')->withRecipe($recipe)->create();
    // ...and a freezer night, which meal history does not track.
    PlanNight::factory()->for($plan)->on('2026-01-10')->withFreezerItem()->create();

    $recorded = (new MealPlanner)->recordPastNightsAsCooked();

    expect($recorded)->toBe(2)
        ->and(MealHistoryEntry::pluck('cooked_on')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-01-08', '2026-01-09']);
});

it('records a night once, however often it is asked', function () {
    $recipe = Recipe::factory()->create();
    $plan = Plan::factory()->window('2026-01-07')->create();
    PlanNight::factory()->for($plan)->on('2026-01-08')->withRecipe($recipe)->create();
    $planner = new MealPlanner;

    expect($planner->recordPastNightsAsCooked())->toBe(1)
        ->and($planner->recordPastNightsAsCooked())->toBe(0)
        ->and(MealHistoryEntry::count())->toBe(1);
});

it('catches meal history up before it plans, so variety is measured against it', function () {
    $recipe = Recipe::factory()->create(['effort_tier' => EffortTier::Workhorse30]);
    Recipe::factory()->count(3)->create(['effort_tier' => EffortTier::Workhorse30]);
    $lastWeek = Plan::factory()->window('2026-01-07')->create();
    PlanNight::factory()->for($lastWeek)->on('2026-01-08')->withRecipe($recipe)->create();

    (new MealPlanner)->planWindow();

    expect(MealHistoryEntry::where('recipe_id', $recipe->id)->exists())->toBeTrue();
});

it('shows what it would plan without saving a thing', function () {
    Recipe::factory()->count(5)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;

    $picks = $planner->previewWindow(seed: 4);

    expect($picks)->toHaveCount(3)
        ->and($picks[0]->hasMeal())->toBeTrue()
        ->and(Plan::count())->toBe(0)
        ->and(PlanNight::count())->toBe(0);
});

it('previews exactly what planning would save', function () {
    Recipe::factory()->count(5)->create(['effort_tier' => EffortTier::Workhorse30]);
    $planner = new MealPlanner;

    $previewed = array_map(fn ($pick) => $pick->describe(), $planner->previewWindow(seed: 4));
    $saved = array_map(fn ($pick) => $pick->describe(), $planner->picksFor($planner->planWindow(seed: 4)));

    expect($saved)->toBe($previewed);
});

it('leaves the freezer count alone in a preview', function () {
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    $soup = FreezerItem::factory()->portions(1)->create();

    (new MealPlanner)->previewWindow(seed: 1);

    expect($soup->refresh()->portions_left)->toBe(1);
});

it('plans a named window instead of the next one', function () {
    Recipe::factory()->count(6)->create(['effort_tier' => EffortTier::Chaos15]);
    $planner = new MealPlanner;

    $plan = $planner->planWindow($planner->windowFor(aDate('2026-01-14')));

    expect($plan->shopping_date->toDateString())->toBe('2026-01-14')
        ->and($plan->nights->map->date->map->toDateString()->all())
        ->toBe(['2026-01-15', '2026-01-16', '2026-01-17', '2026-01-18']);
});

it('reads the shopping days out of Settings', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, [
        ['weekday' => Weekday::Tuesday->value, 'time' => '09:00'],
        ['weekday' => Weekday::Friday->value, 'time' => '17:00'],
    ]);

    // Sunday the 11th: the next shop is Tuesday the 13th, covering Wed to Fri.
    $window = (new MealPlanner)->nextWindow();

    expect($window->shoppingDate->toDateString())->toBe('2026-01-13')
        ->and($window->firstNight->toDateString())->toBe('2026-01-14')
        ->and($window->lastNight->toDateString())->toBe('2026-01-16');
});

it('starts with the picking rules docs/decisions.md records', function () {
    // No rows in the settings table: these are the starting defaults, which is
    // also what HouseholdDefaultsSeeder writes on a fresh database.
    $rules = PlanningRules::fromSettings();

    expect($rules->partnerSoloEffortCap)->toBe(EffortTier::Chaos15)
        ->and($rules->varietyLookbackDays)->toBe(21)
        ->and($rules->freezerMealsOnChaosNightsOnly)->toBeTrue();
});

it('lets the household open the freezer on any night from Settings', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_FREEZER_MEALS_ON_CHAOS_NIGHTS_ONLY, false);
    theWeekIs([Weekday::Monday->value => [CookAssignment::Me, EffortTier::Project60]], CookAssignment::Nobody);
    FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);

    $night = (new MealPlanner)->planWindow()->nights[0];

    expect($night->selection)->toBe(NightSelection::FreezerItem)
        ->and($night->freezerItem->name)->toBe('Frozen minestrone');
});

it('reads the picking rules out of Settings', function () {
    // The household decides her solo nights can take a workhorse after all.
    HouseholdSettings::set(
        HouseholdSettings::KEY_PARTNER_SOLO_EFFORT_CAP,
        EffortTier::Workhorse30->value,
    );
    theWeekIs([], CookAssignment::Partner, EffortTier::Workhorse30);
    Recipe::factory()->count(3)->create([
        'cookable_by' => CookableBy::Partner,
        'effort_tier' => EffortTier::Workhorse30,
    ]);

    $nights = (new MealPlanner)->planWindow()->nights;

    expect($nights->every(fn (PlanNight $night) => $night->selection === NightSelection::Recipe))
        ->toBeTrue();
});

it('plans from the household clock, not the server clock', function () {
    // Late on Saturday the 10th in Toronto is already Sunday the 11th in UTC. The
    // next shop is still Sunday's, and its window still starts on the Monday.
    Carbon::setTestNow(Carbon::parse('2026-01-10 22:30:00', 'America/Toronto'));
    Recipe::factory()->count(4)->create(['effort_tier' => EffortTier::Chaos15]);

    $plan = (new MealPlanner)->planWindow();

    expect($plan->shopping_date->toDateString())->toBe('2026-01-11')
        ->and($plan->first_night->toDateString())->toBe('2026-01-12');
});

it('clears a locked night when nobody is cooking after all', function () {
    // A lock says "keep this dinner"; an override to nobody says there isn't one.
    // Both cannot hold, and the household just typed the second — so the meal
    // goes and the freezer portion comes back, lock or no lock. Leaving the meal
    // would have MM-8, MM-9 and MM-14 all showing "Nobody cooking" against a
    // dinner, and would strand the portion for ever.
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    $soup = FreezerItem::factory()->portions(1)->create();
    $planner = new MealPlanner;
    $night = $planner->chooseMeal($planner->planWindow()->nights[0], $soup, lock: true);

    expect($soup->refresh()->portions_left)->toBe(0)
        ->and($night->is_locked)->toBeTrue();

    $night = $planner->setOverride($night, cook: CookAssignment::Nobody, note: 'Eating out');

    expect($night->cook)->toBe(CookAssignment::Nobody)
        ->and($night->selection)->toBe(NightSelection::NightOff)
        ->and($night->freezer_item_id)->toBeNull()
        ->and($night->holds_freezer_portion)->toBeFalse()
        ->and($soup->refresh()->portions_left)->toBe(1)
        // The lock stands: the household said this night is settled.
        ->and($night->is_locked)->toBeTrue();
});

it('tries a night nothing fitted again when the vault has changed', function () {
    // The reason the night carries tells the household what to add ("not tagged
    // for Partner", "nothing short enough"). Adding it has to be enough: a plain
    // plan run is what they will do next, and before this it changed nothing —
    // only --replan filled the night, and that re-rolled every other night too.
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    $planner = new MealPlanner;

    expect($planner->planWindow()->nights->every(fn (PlanNight $night) => $night->nothingFits()))
        ->toBeTrue();

    // Three quick meals she likes making, which is exactly what the note asked for.
    Recipe::factory()->count(3)->create([
        'cookable_by' => CookableBy::Partner,
        'effort_tier' => EffortTier::Chaos15,
    ]);

    $nights = $planner->planWindow()->nights;

    expect($nights->every(fn (PlanNight $night) => $night->selection === NightSelection::Recipe))
        ->toBeTrue()
        ->and($nights->pluck('pick_reason')->filter())->toBeEmpty();
});

it('leaves the nights that did fit alone while it retries the one that did not', function () {
    // Monday and Tuesday are fine; Wednesday is my partner on her own with
    // nothing quick in the vault. Filling Wednesday must not re-roll the other two.
    theWeekIs([Weekday::Wednesday->value => [CookAssignment::Partner, EffortTier::Chaos15]]);
    Recipe::factory()->count(4)->create([
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Workhorse30,
    ]);
    $planner = new MealPlanner;
    $before = $planner->planWindow(seed: 1)->nights->pluck('recipe_id', 'date')->all();

    expect($planner->planFor($planner->nextWindow())->nights[2]->nothingFits())->toBeTrue();

    Recipe::factory()->create([
        'name' => 'Quick pasta',
        'cookable_by' => CookableBy::Partner,
        'effort_tier' => EffortTier::Chaos15,
    ]);

    $nights = $planner->planWindow(seed: 2)->nights;

    expect($nights[0]->recipe_id)->toBe($before[array_keys($before)[0]])
        ->and($nights[1]->recipe_id)->toBe($before[array_keys($before)[1]])
        ->and($nights[2]->recipe->name)->toBe('Quick pasta');
});

it('says nothing fits again, with a fresh reason, when it still does not', function () {
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    $planner = new MealPlanner;
    $plan = $planner->planWindow();

    expect($planner->picksFor($plan)[0]->explain(HouseholdSettings::cookLabel(...)))
        ->toContain('the recipe vault is empty');

    // The household adds a recipe, but not one she would make on her own. The
    // night still fits nothing, and says something more useful about why.
    Recipe::factory()->create(['cookable_by' => CookableBy::Me, 'effort_tier' => EffortTier::Chaos15]);
    $plan = $planner->planWindow();

    expect($plan->nights->every(fn (PlanNight $night) => $night->nothingFits()))->toBeTrue()
        ->and($planner->picksFor($plan)[0]->explain(HouseholdSettings::cookLabel(...)))
        ->toContain('1 of 1 recipes are not tagged for Partner');
});

it('keeps a locked night that nothing fitted', function () {
    // The household locked a dead end on purpose — they know Wednesday is a
    // write-off. A plain plan run leaves it alone.
    theWeekIs([], CookAssignment::Partner, EffortTier::Chaos15);
    $planner = new MealPlanner;
    $locked = $planner->lockNight($planner->planWindow()->nights[0]);

    Recipe::factory()->count(3)->create([
        'cookable_by' => CookableBy::Partner,
        'effort_tier' => EffortTier::Chaos15,
    ]);
    $planner->planWindow();

    expect($locked->refresh()->nothingFits())->toBeTrue();
});
