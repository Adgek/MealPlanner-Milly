<?php

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Models\FreezerItem;
use App\Models\Plan;
use App\Models\PlanGroceryItem;
use App\Models\PlanNight;
use App\Models\Recipe;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/*
 * A plan is one shopping window: the shop, the nights it covers, and the grocery
 * checklist. MM-6 writes it, MM-8 edits it.
 */

it('creates a plan for a shopping window and reads it back', function () {
    // The default Sunday window: shop Sunday, cover Monday to Wednesday.
    Plan::create([
        'shopping_date' => '2026-01-11',
        'first_night' => '2026-01-12',
        'last_night' => '2026-01-14',
    ]);

    $plan = Plan::firstWhere('shopping_date', '2026-01-11');

    expect($plan->shopping_date->toDateString())->toBe('2026-01-11')
        ->and($plan->first_night->toDateString())->toBe('2026-01-12')
        ->and($plan->last_night->toDateString())->toBe('2026-01-14');
});

it('keeps window dates as calendar dates, with no time to drift', function () {
    $plan = Plan::factory()->window('2026-01-11')->create();

    // Stored as DATE. A UTC timestamp would show the 10th to a Toronto reader.
    expect($plan->refresh()->shopping_date->format('H:i:s'))->toBe('00:00:00')
        ->and($plan->shopping_date->toDateString())->toBe('2026-01-11');
});

it('holds one plan per shopping window', function () {
    Plan::factory()->window('2026-01-11')->create();

    expect(fn () => Plan::factory()->window('2026-01-11')->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('reads its nights back in date order', function () {
    $plan = Plan::factory()->window('2026-01-11')->create();
    // Inserted out of order on purpose.
    PlanNight::factory()->for($plan)->on('2026-01-14')->create();
    PlanNight::factory()->for($plan)->on('2026-01-12')->create();
    PlanNight::factory()->for($plan)->on('2026-01-13')->create();

    expect($plan->refresh()->nights->map->date->map->toDateString()->all())
        ->toBe(['2026-01-12', '2026-01-13', '2026-01-14']);
});

it('starts a night unplanned, before the picker has run', function () {
    $night = PlanNight::factory()->create();

    expect($night->selection)->toBeNull()
        ->and($night->isPlanned())->toBeFalse()
        ->and($night->recipe_id)->toBeNull()
        ->and($night->freezer_item_id)->toBeNull();
});

it('plans a night with a recipe', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    $night = PlanNight::factory()->withRecipe($chili)->create();

    $night->refresh();

    expect($night->selection)->toBe(NightSelection::Recipe)
        ->and($night->recipe->name)->toBe('Chili')
        ->and($night->freezerItem)->toBeNull()
        ->and($night->isPlanned())->toBeTrue();
});

it('plans a night with a freezer meal', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen minestrone']);
    $night = PlanNight::factory()->withFreezerItem($soup)->create();

    $night->refresh();

    expect($night->selection)->toBe(NightSelection::FreezerItem)
        ->and($night->freezerItem->name)->toBe('Frozen minestrone')
        ->and($night->recipe)->toBeNull();
});

it('plans a night off', function () {
    $night = PlanNight::factory()->nightOff('Out for dinner')->create();

    $night->refresh();

    expect($night->selection)->toBe(NightSelection::NightOff)
        ->and($night->recipe_id)->toBeNull()
        ->and($night->freezer_item_id)->toBeNull()
        ->and($night->cook)->toBe(CookAssignment::Nobody)
        ->and($night->cook->cooks())->toBeFalse()
        ->and($night->override_note)->toBe('Out for dinner');
});

it('records a one-off override for a single date', function () {
    $night = PlanNight::factory()
        ->overridden(CookAssignment::Partner, EffortTier::Chaos15, 'I am travelling')
        ->create();

    $night->refresh();

    expect($night->overrides_schedule)->toBeTrue()
        ->and($night->override_note)->toBe('I am travelling')
        ->and($night->cook)->toBe(CookAssignment::Partner)
        ->and($night->time_budget)->toBe(EffortTier::Chaos15);
});

it('locks a night so a re-roll leaves it alone', function () {
    $locked = PlanNight::factory()->locked()->create();
    $open = PlanNight::factory()->create();

    expect($locked->is_locked)->toBeTrue()
        ->and($locked->isRerollable())->toBeFalse()
        ->and($open->isRerollable())->toBeTrue();
});

it('holds one night per date in a plan', function () {
    $plan = Plan::factory()->create();
    PlanNight::factory()->for($plan)->on('2026-01-12')->create();

    expect(fn () => PlanNight::factory()->for($plan)->on('2026-01-12')->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps the night but forgets the recipe when the recipe is deleted', function () {
    $recipe = Recipe::factory()->create();
    $night = PlanNight::factory()->withRecipe($recipe)->create();

    $recipe->delete();

    // The night still happened; it just no longer points at a vault entry.
    expect($night->refresh()->recipe_id)->toBeNull();
});

it('stores the grocery checklist against the plan', function () {
    $plan = Plan::factory()->create();

    $plan->groceryItems()->createMany([
        ['name' => 'Ground beef', 'quantity' => 2, 'unit' => 'lb', 'store_section' => 'Meat'],
        ['name' => 'Olive oil', 'pantry_basic' => true, 'store_section' => 'Pantry'],
    ]);

    $items = $plan->refresh()->groceryItems;

    expect($items)->toHaveCount(2)
        ->and($items->firstWhere('name', 'Ground beef')->quantity)->toBe(2.0)
        ->and($items->firstWhere('name', 'Olive oil')->pantry_basic)->toBeTrue()
        ->and($items->every(fn (PlanGroceryItem $item) => $item->checked === false))->toBeTrue();
});

it('ticks a grocery line off and records when', function () {
    $item = PlanGroceryItem::factory()->create();

    expect($item->check())->toBeTrue();

    $item->refresh();

    expect($item->checked)->toBeTrue()
        ->and($item->checked_at)->not->toBeNull();
});

it('does not overwrite a tick when the other phone ticks it again', function () {
    // Both partners are at the fridge; the second tap must not move the timestamp.
    $item = PlanGroceryItem::factory()->create();
    $item->check();
    $firstTick = $item->refresh()->checked_at;

    expect($item->check())->toBeFalse()
        ->and($item->refresh()->checked_at->eq($firstTick))->toBeTrue();
});

it('settles two phones ticking at once in the database', function () {
    // The real race, which the one above does not reach: both partners opened the
    // page while the line was unticked, so each holds a row that says `checked =
    // false`. Reading that from the instance in hand would let the second write
    // through and move the timestamp; the guard has to be the update itself.
    $item = PlanGroceryItem::factory()->create();
    $onePhone = PlanGroceryItem::query()->findOrFail($item->getKey());
    $theOther = PlanGroceryItem::query()->findOrFail($item->getKey());

    Carbon::setTestNow(Carbon::parse('2026-01-11 10:15:00', 'America/Toronto'));
    expect($onePhone->check())->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-01-11 10:15:04', 'America/Toronto'));
    expect($theOther->check())->toBeFalse();

    expect($item->refresh()->checked)->toBeTrue()
        ->and($item->checked_at->format('H:i:s'))->toBe('15:15:00')
        // The phone that lost sees the tick that won, not the one it tried to make.
        ->and($theOther->checked_at->eq($item->checked_at))->toBeTrue();

    Carbon::setTestNow();
});

it('puts a grocery line back on the list', function () {
    $item = PlanGroceryItem::factory()->checked()->create();

    $item->uncheck();
    $item->refresh();

    expect($item->checked)->toBeFalse()
        ->and($item->checked_at)->toBeNull();
});

it('clears the nights and the checklist when the plan is deleted', function () {
    $plan = Plan::factory()->create();
    PlanNight::factory()->for($plan)->count(3)->create();
    PlanGroceryItem::factory()->for($plan)->count(4)->create();

    $plan->delete();

    expect(PlanNight::count())->toBe(0)
        ->and(PlanGroceryItem::count())->toBe(0);
});
