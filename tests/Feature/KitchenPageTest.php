<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Livewire\Kitchen;
use App\Models\FreezerItem;
use App\Models\Ingredient;
use App\Models\KitchenProject;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use Livewire\Livewire;

/*
 * The kitchen page (MM-15): the recurring projects, and the freezer stash they
 * fill.
 *
 * The stash is the dangerous half. A planned freezer meal holds a portion out of
 * the count (MM-6), so removing an item has to hand the portion back and leave
 * the reservation gone — and a night that has gone by is the record that a
 * portion was eaten, which must not move.
 */

/**
 * A date relative to today on the kitchen clock.
 */
function aKitchenNight(int $daysFromToday): string
{
    return (new MealPlanner)->today()->addDays($daysFromToday)->toDateString();
}

/*
|--------------------------------------------------------------------------
| The freezer stash
|--------------------------------------------------------------------------
*/

it('opens with the stash the household has', function () {
    FreezerItem::factory()->create([
        'name' => 'Frozen minestrone',
        'portions_left' => 4,
        'thaw_lead_time_hours' => 24,
        'kid_off_ramp' => 'Strain a portion so it is mostly pasta.',
    ]);

    Livewire::test(Kitchen::class)
        ->assertOk()
        ->assertCount('rows', 1)
        ->assertSet('rows.0.name', 'Frozen minestrone')
        ->assertSet('rows.0.portions_left', '4')
        ->assertSet('rows.0.thaw_lead_time_hours', '24')
        ->assertSet('rows.0.kid_off_ramp', 'Strain a portion so it is mostly pasta.');
});

it('adds a freezer meal', function () {
    Livewire::test(Kitchen::class)
        ->call('addRow')
        ->set('rows.0.name', 'Frozen chili')
        ->set('rows.0.portions_left', '6')
        ->set('rows.0.thaw_lead_time_hours', '12')
        ->set('rows.0.kid_off_ramp', 'Cheese on top.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $item = FreezerItem::query()->firstWhere('name', 'Frozen chili');

    expect($item->portions_left)->toBe(6)
        ->and($item->thaw_lead_time_hours)->toBe(12)
        ->and($item->kid_off_ramp)->toBe('Cheese on top.')
        ->and($item->isAvailable())->toBeTrue();
});

it('bumps the counts in one save', function () {
    $soup = FreezerItem::factory()->portions(1)->create(['name' => 'Frozen minestrone']);
    $chili = FreezerItem::factory()->portions(0)->create(['name' => 'Frozen chili']);

    Livewire::test(Kitchen::class)
        // Alphabetical: chili first.
        ->set('rows.0.portions_left', '4')
        ->set('rows.1.portions_left', '2')
        ->call('save')
        ->assertHasNoErrors();

    expect($chili->fresh()->portions_left)->toBe(4)
        ->and($soup->fresh()->portions_left)->toBe(2);
});

it('lets a count go to zero, which means the box is empty', function () {
    $soup = FreezerItem::factory()->portions(3)->create(['name' => 'Frozen minestrone']);

    Livewire::test(Kitchen::class)
        ->set('rows.0.portions_left', '0')
        ->call('save')
        ->assertHasNoErrors();

    // Zero is normal: the row is the catalogue entry as well as the count (MM-3).
    expect($soup->fresh()->portions_left)->toBe(0)
        ->and(FreezerItem::query()->count())->toBe(1);
});

it('refuses a freezer meal with no name', function () {
    FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);

    Livewire::test(Kitchen::class)
        ->set('rows.0.name', '')
        ->call('save')
        ->assertHasErrors('rows.0.name')
        ->assertSee('Name the freezer meal, or remove the row.');
});

it('refuses a count or a thaw time that is not a sane number', function (string $field, string $value, string $message) {
    FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone', 'thaw_lead_time_hours' => 24]);

    Livewire::test(Kitchen::class)
        ->set("rows.0.{$field}", $value)
        ->call('save')
        ->assertHasErrors("rows.0.{$field}")
        ->assertSee($message);

    $item = FreezerItem::query()->firstWhere('name', 'Frozen minestrone');

    expect($item->portions_left)->toBe(2)
        ->and($item->thaw_lead_time_hours)->toBe(24);
})->with([
    'empty count' => ['portions_left', '', 'Say how many portions are left.'],
    'negative count' => ['portions_left', '-1', 'There cannot be fewer than no portions.'],
    'half a portion' => ['portions_left', '1.5', 'Give the portions as a whole number.'],
    'a freezer full' => ['portions_left', '100', 'That is more portions than the freezer holds.'],
    'empty thaw' => ['thaw_lead_time_hours', '', 'Say how long it needs out of the freezer.'],
    'negative thaw' => ['thaw_lead_time_hours', '-2', 'A thaw time cannot be negative.'],
    'a week thawing' => ['thaw_lead_time_hours', '200', 'A week out of the freezer is not thawing.'],
]);

it('refuses a name another freezer meal already has', function () {
    FreezerItem::factory()->create(['name' => 'Frozen minestrone']);

    Livewire::test(Kitchen::class)
        ->call('addRow')
        ->set('rows.1.name', 'Frozen minestrone')
        ->call('save')
        ->assertHasErrors('rows.1.name')
        ->assertSee('There is already a freezer meal with that name.');

    expect(FreezerItem::query()->count())->toBe(1);
});

it('writes no part of the stash when one row is wrong', function () {
    $soup = FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);
    $chili = FreezerItem::factory()->portions(1)->create(['name' => 'Frozen chili']);

    Livewire::test(Kitchen::class)
        ->set('rows.0.portions_left', '5')
        ->set('rows.1.portions_left', '-3')
        ->call('save')
        ->assertHasErrors('rows.1.portions_left');

    expect($chili->fresh()->portions_left)->toBe(1)
        ->and($soup->fresh()->portions_left)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Taking a freezer meal out of the stash
|--------------------------------------------------------------------------
*/

it('says what a removal would touch, portions and all', function () {
    $soup = FreezerItem::factory()->portions(3)->create(['name' => 'Frozen minestrone']);
    KitchenProject::factory()->producing($soup)->create(['name' => 'Big batch minestrone']);

    Livewire::test(Kitchen::class)
        ->call('confirmRemove', $soup->id)
        ->assertSee('3 portions are still counted in the stash')
        ->assertSee('Big batch minestrone restocks it')
        // A project still points at it, so there is nothing to delete cleanly.
        ->assertDontSee('Delete it for good');
});

it('hands a held portion back when it takes an item out of the stash', function () {
    $planner = new MealPlanner;

    $soup = FreezerItem::factory()->portions(2)->create(['name' => 'Frozen minestrone']);
    Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $night = PlanNight::factory()->for(Plan::factory())->on(aKitchenNight(1))->create();
    $planner->chooseMeal($night, $soup);

    expect($soup->fresh()->portions_left)->toBe(1);

    Livewire::test(Kitchen::class)
        ->call('confirmRemove', $soup->id)
        ->call('retire', $soup->id)
        ->assertSee('Frozen minestrone is out of the vault')
        ->assertCount('rows', 0);

    // The portion is back in the freezer and still counted; the reservation is
    // gone, and the night has another dinner.
    expect($soup->fresh()->portions_left)->toBe(2)
        ->and($soup->fresh()->isRetired())->toBeTrue()
        ->and($night->fresh()->holdsFreezerPortion())->toBeFalse()
        ->and($night->fresh()->freezer_item_id)->toBeNull();
});

it('leaves a portion eaten on a night that has gone by out of the count', function () {
    $soup = FreezerItem::factory()->portions(1)->create(['name' => 'Frozen minestrone']);

    PlanNight::factory()->for(Plan::factory())->on(aKitchenNight(-3))
        ->withFreezerItem($soup)
        ->create(['holds_freezer_portion' => true]);

    Livewire::test(Kitchen::class)
        ->call('confirmRemove', $soup->id)
        ->assertSee('The plan says you ate it on')
        ->call('retire', $soup->id);

    expect($soup->fresh()->portions_left)->toBe(1);
});

it('brings a freezer meal back with its count intact', function () {
    $soup = FreezerItem::factory()->portions(3)->retired()->create(['name' => 'Frozen minestrone']);

    Livewire::test(Kitchen::class)
        ->assertSee('Out of the stash')
        ->assertSee('3 portions still counted')
        ->call('bringBack', $soup->id)
        ->assertSee('Frozen minestrone is back in the stash');

    expect($soup->fresh()->isRetired())->toBeFalse()
        ->and($soup->fresh()->portions_left)->toBe(3);
});

it('deletes a freezer meal nothing points at', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen mistake']);

    Livewire::test(Kitchen::class)
        ->call('confirmRemove', $soup->id)
        ->assertSee('Delete it for good')
        ->call('deleteForGood', $soup->id)
        ->assertSee('Frozen mistake is deleted.');

    expect(FreezerItem::query()->count())->toBe(0);
});

it('refuses to delete a freezer meal a past night points at', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen minestrone']);
    PlanNight::factory()->for(Plan::factory())->on(aKitchenNight(-2))->withFreezerItem($soup)->create();

    Livewire::test(Kitchen::class)
        ->call('confirmRemove', $soup->id)
        ->call('deleteForGood', $soup->id)
        ->assertSee('Frozen minestrone was kept.');

    expect(FreezerItem::query()->whereKey($soup->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Kitchen projects
|--------------------------------------------------------------------------
*/

it('shows the projects with their cadence and what they make', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen minestrone']);
    KitchenProject::factory()->producing($soup, 6)->withIngredients(3)->neverDone()->create([
        'name' => 'Big batch minestrone',
        'every_days' => 21,
    ]);

    $this->get(route('kitchen'))
        ->assertOk()
        ->assertSee('Big batch minestrone')
        ->assertSee('Every 21 days')
        // Never done, so it is due now.
        ->assertSee('due now')
        ->assertSee('Makes 6 portions of Frozen minestrone')
        ->assertSee('3 ingredients')
        ->assertSee('never done');
});

it('says when a project is next due', function () {
    KitchenProject::factory()->create([
        'name' => 'Fresh hummus',
        'every_days' => 7,
        'last_done_on' => aKitchenNight(-2),
    ]);

    $due = (new MealPlanner)->today()->addDays(5)->format('D j M');

    $this->get(route('kitchen'))->assertOk()->assertSee('due '.$due);
});

it('says what removing a project takes with it', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen minestrone']);
    $project = KitchenProject::factory()->producing($soup, 6)->withIngredients(2)->create([
        'name' => 'Big batch minestrone',
    ]);

    Livewire::test(Kitchen::class)
        ->call('confirmRemoveProject', $project->id)
        ->assertSee('Its 2 ingredients go with it')
        ->assertSee('Nothing will top up Frozen minestrone any more');
});

it('removes a project and its ingredients, leaving the freezer count alone', function () {
    $soup = FreezerItem::factory()->portions(4)->create(['name' => 'Frozen minestrone']);
    $project = KitchenProject::factory()->producing($soup, 6)->withIngredients(2)->create([
        'name' => 'Big batch minestrone',
    ]);

    Livewire::test(Kitchen::class)
        ->call('confirmRemoveProject', $project->id)
        ->call('removeProject', $project->id)
        ->assertSee('Big batch minestrone is removed.')
        ->assertSet('removingProject', null);

    expect(KitchenProject::query()->count())->toBe(0)
        // Ingredients are polymorphic, so the model event has to have fired.
        ->and(Ingredient::query()->where('ingredientable_id', $project->id)->count())->toBe(0)
        ->and($soup->fresh()->portions_left)->toBe(4);
});

it('offers the way to the project editor', function () {
    $project = KitchenProject::factory()->create();

    $this->get(route('kitchen'))
        ->assertOk()
        ->assertSee('href="'.route('kitchen.projects.new').'"', escape: false)
        ->assertSee('href="'.route('kitchen.projects.edit', $project).'"', escape: false);
});

it('says what an empty kitchen means', function () {
    $this->get(route('kitchen'))
        ->assertOk()
        ->assertSee('No kitchen projects yet')
        ->assertSee('Nothing in the freezer yet');
});
