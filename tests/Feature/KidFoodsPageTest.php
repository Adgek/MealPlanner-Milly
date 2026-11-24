<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Grocery\GroceryListBuilder;
use App\Livewire\KidFoods;
use App\Models\KidBackupFood;
use App\Models\Recipe;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The kid off-ramp inventory (MM-15, AppPlan.md section 3).
 *
 * The amount is split into a quantity and a unit, the same shape as an
 * ingredient, because these go on every grocery list beside the meals' own
 * ingredients (MM-7).
 */

it('opens with the list the household keeps, in reading order', function () {
    KidBackupFood::factory()->create(['name' => 'Yogurt tubes', 'quantity' => 1, 'unit' => 'box']);
    KidBackupFood::factory()->create(['name' => 'Bread', 'quantity' => 1, 'unit' => 'loaf']);

    Livewire::test(KidFoods::class)
        ->assertOk()
        ->assertCount('rows', 2)
        ->assertSet('rows.0.name', 'Bread')
        ->assertSet('rows.0.unit', 'loaf')
        ->assertSet('rows.1.name', 'Yogurt tubes');
});

it('adds a backup food', function () {
    Livewire::test(KidFoods::class)
        ->call('addRow')
        ->set('rows.0.name', 'Chicken nuggets')
        ->set('rows.0.quantity', '2')
        ->set('rows.0.unit', 'bag')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $food = KidBackupFood::query()->firstWhere('name', 'Chicken nuggets');

    expect($food->quantity)->toBe(2.0)
        ->and($food->unit)->toBe('bag');
});

it('saves a food nobody counts', function () {
    Livewire::test(KidFoods::class)
        ->call('addRow')
        ->set('rows.0.name', 'Apple sauce')
        ->set('rows.0.quantity', '')
        ->set('rows.0.unit', '')
        ->call('save')
        ->assertHasNoErrors();

    $food = KidBackupFood::query()->firstWhere('name', 'Apple sauce');

    expect($food->quantity)->toBeNull()
        ->and($food->unit)->toBeNull();
});

it('edits several foods in one save', function () {
    $bread = KidBackupFood::factory()->create(['name' => 'Bread', 'quantity' => 1, 'unit' => 'loaf']);
    $nuggets = KidBackupFood::factory()->create(['name' => 'Chicken nuggets', 'quantity' => 1, 'unit' => 'bag']);

    Livewire::test(KidFoods::class)
        ->set('rows.0.quantity', '2')
        ->set('rows.1.unit', 'box')
        ->call('save')
        ->assertHasNoErrors();

    expect($bread->fresh()->quantity)->toBe(2.0)
        ->and($nuggets->fresh()->unit)->toBe('box')
        ->and(KidBackupFood::query()->count())->toBe(2);
});

it('keeps the household’s own units, whatever a recipe calls the same thing', function () {
    // MM-7 keeps these as two lines read together under one heading: a bag of
    // tortillas and "8 count" are not the same shopping decision.
    Recipe::factory()->create(['name' => 'White Boy Tacos'])
        ->ingredients()->create(['name' => 'Tortillas', 'quantity' => 8, 'unit' => 'count']);

    Livewire::test(KidFoods::class)
        ->call('addRow')
        ->set('rows.0.name', 'Tortillas')
        ->set('rows.0.quantity', '1')
        ->set('rows.0.unit', 'bag')
        ->call('save')
        ->assertHasNoErrors();

    expect(KidBackupFood::query()->firstWhere('name', 'Tortillas')->unit)->toBe('bag');
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses a food with no name', function () {
    Livewire::test(KidFoods::class)
        ->call('addRow')
        ->set('rows.0.quantity', '2')
        ->call('save')
        ->assertHasErrors('rows.0.name')
        ->assertSee('Name the backup food, or remove the row.');

    expect(KidBackupFood::query()->count())->toBe(0);
});

it('refuses a food already on the list', function () {
    KidBackupFood::factory()->create(['name' => 'Bread']);

    Livewire::test(KidFoods::class)
        ->call('addRow')
        ->set('rows.1.name', 'Bread')
        ->call('save')
        ->assertHasErrors('rows.1.name')
        ->assertSee('That backup food is already on the list.');

    expect(KidBackupFood::query()->count())->toBe(1);
});

it('refuses the same new name twice in one form', function () {
    Livewire::test(KidFoods::class)
        ->call('addRow')
        ->call('addRow')
        ->set('rows.0.name', 'Bread')
        ->set('rows.1.name', 'bread')
        ->call('save')
        ->assertHasErrors('rows.0.name')
        ->assertSee('That name is on the list twice.');

    expect(KidBackupFood::query()->count())->toBe(0);
});

it('refuses an amount that is not a number', function (string $value, string $message) {
    KidBackupFood::factory()->create(['name' => 'Bread', 'quantity' => 1, 'unit' => 'loaf']);

    Livewire::test(KidFoods::class)
        ->set('rows.0.quantity', $value)
        ->call('save')
        ->assertHasErrors('rows.0.quantity')
        ->assertSee($message);

    expect(KidBackupFood::query()->firstWhere('name', 'Bread')->quantity)->toBe(1.0);
})->with([
    'words' => ['a few', 'Give the amount as a number'],
    'negative' => ['-1', 'An amount cannot be negative.'],
    'three decimals' => ['1.333', 'two decimal places'],
]);

it('writes nothing at all when one row is wrong', function () {
    $bread = KidBackupFood::factory()->create(['name' => 'Bread', 'quantity' => 1]);
    $nuggets = KidBackupFood::factory()->create(['name' => 'Chicken nuggets', 'quantity' => 1]);

    Livewire::test(KidFoods::class)
        ->set('rows.0.quantity', '3')
        ->set('rows.1.name', '')
        ->call('save')
        ->assertHasErrors('rows.1.name')
        ->assertSet('saved', false)
        ->assertSee('Nothing was saved.');

    expect($bread->fresh()->quantity)->toBe(1.0)
        ->and($nuggets->fresh()->name)->toBe('Chicken nuggets');
});

/*
|--------------------------------------------------------------------------
| Removing one
|--------------------------------------------------------------------------
*/

it('takes a food off the list, and says what that means for a list already out', function () {
    $bread = KidBackupFood::factory()->create(['name' => 'Bread']);

    Livewire::test(KidFoods::class)
        ->call('confirmRemove', $bread->id)
        ->assertSee('It stops being added to the grocery list.')
        ->call('remove', $bread->id)
        ->assertSee('Bread is off the list.')
        ->assertCount('rows', 0);

    expect(KidBackupFood::query()->count())->toBe(0);
});

it('leaves the stored grocery list to catch up with the change itself', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();

    $chili = Recipe::factory()->create([
        'name' => 'Chili',
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Workhorse30,
    ]);
    $chili->ingredients()->create(['name' => 'Ground beef', 'quantity' => 1, 'unit' => 'lb', 'store_section' => 'Meat']);

    $plan = aPlannedWindowOf($chili);
    $groceries = new GroceryListBuilder;

    Livewire::test(KidFoods::class)
        ->call('addRow')
        ->set('rows.0.name', 'Chicken nuggets')
        ->set('rows.0.quantity', '1')
        ->set('rows.0.unit', 'bag')
        ->call('save')
        ->assertHasNoErrors();

    // The editor writes nothing to the stored checklist: both phones share it,
    // and MM-7's currentFor() is what brings it up to date.
    expect($groceries->isStale($plan->fresh()))->toBeTrue()
        ->and($groceries->listFor($plan->fresh())->lineFor('Chicken nuggets', 'bag'))->toBeNull()
        ->and($groceries->currentFor($plan->fresh())->lineFor('Chicken nuggets', 'bag'))->not->toBeNull();

    Carbon::setTestNow();
});
