<?php

use App\Livewire\BulkIngredients;
use App\Models\BulkIngredient;
use App\Models\Recipe;
use App\Settings\HouseholdSettings;
use Livewire\Livewire;

/*
 * The bulk ingredients page (MM-15): the shared packs a recipe is tagged with,
 * and how long the raw second half keeps.
 *
 * The fridge life is the interesting field. It may be left empty, in which case
 * the household's raw-split default from Settings applies (MM-3) — so "empty"
 * has to stay null rather than becoming a zero that would silently mean "freeze
 * it immediately".
 */

it('opens with the packs the household has, in reading order', function () {
    BulkIngredient::factory()->create(['name' => 'Tortillas', 'fridge_life_days' => null]);
    BulkIngredient::factory()->create(['name' => 'Ground beef', 'fridge_life_days' => 2]);

    Livewire::test(BulkIngredients::class)
        ->assertOk()
        ->assertCount('rows', 2)
        ->assertSet('rows.0.name', 'Ground beef')
        ->assertSet('rows.0.fridge_life_days', '2')
        ->assertSet('rows.1.name', 'Tortillas')
        ->assertSet('rows.1.fridge_life_days', '');
});

it('says what an empty fridge life falls back to', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS, 3);

    $this->get(route('recipes.bulk-ingredients'))
        ->assertOk()
        ->assertSee('which is 3 days');
});

it('adds a pack', function () {
    Livewire::test(BulkIngredients::class)
        ->call('addRow')
        ->set('rows.0.name', 'Ground beef')
        ->set('rows.0.fridge_life_days', '2')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true)
        ->assertSee('Saved.');

    $ingredient = BulkIngredient::query()->firstWhere('name', 'Ground beef');

    expect($ingredient->fridge_life_days)->toBe(2)
        ->and($ingredient->fridgeLifeDays())->toBe(2);
});

it('keeps an empty fridge life empty, so the setting applies', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS, 4);

    Livewire::test(BulkIngredients::class)
        ->call('addRow')
        ->set('rows.0.name', 'Tortillas')
        ->set('rows.0.fridge_life_days', '')
        ->call('save')
        ->assertHasNoErrors();

    $ingredient = BulkIngredient::query()->firstWhere('name', 'Tortillas');

    expect($ingredient->fridge_life_days)->toBeNull()
        ->and($ingredient->fridgeLifeDays())->toBe(4);
});

it('edits several packs in one save', function () {
    $beef = BulkIngredient::factory()->create(['name' => 'Ground beef', 'fridge_life_days' => 2]);
    $tortillas = BulkIngredient::factory()->create(['name' => 'Tortillas', 'fridge_life_days' => 5]);

    Livewire::test(BulkIngredients::class)
        ->set('rows.0.fridge_life_days', '3')
        ->set('rows.1.name', 'Corn tortillas')
        ->call('save')
        ->assertHasNoErrors();

    expect($beef->fresh()->fridge_life_days)->toBe(3)
        ->and($tortillas->fresh()->name)->toBe('Corn tortillas')
        ->and(BulkIngredient::query()->count())->toBe(2);
});

it('retires the saved banner as soon as something is edited again', function () {
    Livewire::test(BulkIngredients::class)
        ->call('addRow')
        ->set('rows.0.name', 'Ground beef')
        ->call('save')
        ->assertSet('saved', true)
        ->set('rows.0.fridge_life_days', '4')
        ->assertSet('saved', false);
});

it('drops a row that was never saved without asking', function () {
    Livewire::test(BulkIngredients::class)
        ->call('addRow')
        ->set('rows.0.name', 'Typo')
        ->call('dropRow', 0)
        ->assertCount('rows', 0)
        ->assertSet('removing', null);

    expect(BulkIngredient::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses a pack with no name', function () {
    BulkIngredient::factory()->create(['name' => 'Ground beef']);

    Livewire::test(BulkIngredients::class)
        ->set('rows.0.name', '')
        ->call('save')
        ->assertHasErrors('rows.0.name')
        ->assertSee('Name the bulk ingredient, or remove the row.');

    expect(BulkIngredient::query()->firstWhere('name', 'Ground beef'))->not->toBeNull();
});

it('refuses a name another pack already has', function () {
    BulkIngredient::factory()->create(['name' => 'Ground beef']);

    Livewire::test(BulkIngredients::class)
        ->call('addRow')
        ->set('rows.1.name', 'Ground beef')
        ->call('save')
        ->assertHasErrors('rows.1.name')
        ->assertSee('There is already a bulk ingredient with that name.');

    expect(BulkIngredient::query()->count())->toBe(1);
});

it('refuses the same new name twice in one form', function () {
    // Neither row is in the database, so `unique` passes both and the save would
    // collide on the index halfway through.
    Livewire::test(BulkIngredients::class)
        ->call('addRow')
        ->call('addRow')
        ->set('rows.0.name', 'Ground beef')
        ->set('rows.1.name', 'ground beef')
        ->call('save')
        ->assertHasErrors('rows.0.name')
        ->assertSee('That name is on the list twice.');

    expect(BulkIngredient::query()->count())->toBe(0);
});

it('refuses a fridge life that is not a sane number of days', function (string $value, string $message) {
    BulkIngredient::factory()->create(['name' => 'Ground beef', 'fridge_life_days' => 2]);

    Livewire::test(BulkIngredients::class)
        ->set('rows.0.fridge_life_days', $value)
        ->call('save')
        ->assertHasErrors('rows.0.fridge_life_days')
        ->assertSee($message);

    expect(BulkIngredient::query()->firstWhere('name', 'Ground beef')->fridge_life_days)->toBe(2);
})->with([
    'zero' => ['0', 'A raw split has to keep for at least a day.'],
    'a fortnight' => ['15', 'Two weeks in the fridge is not a raw split.'],
    'words' => ['two', 'Give the fridge life as a whole number of days, or leave it empty.'],
    'half a day' => ['1.5', 'Give the fridge life as a whole number of days, or leave it empty.'],
]);

it('writes nothing at all when one row is wrong', function () {
    BulkIngredient::factory()->create(['name' => 'Ground beef', 'fridge_life_days' => 2]);
    BulkIngredient::factory()->create(['name' => 'Tortillas', 'fridge_life_days' => 5]);

    Livewire::test(BulkIngredients::class)
        ->set('rows.0.fridge_life_days', '3')
        ->set('rows.1.name', '')
        ->call('save')
        ->assertHasErrors('rows.1.name')
        ->assertSet('saved', false);

    expect(BulkIngredient::query()->firstWhere('name', 'Ground beef')->fridge_life_days)->toBe(2)
        ->and(BulkIngredient::query()->firstWhere('name', 'Tortillas'))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Removing one
|--------------------------------------------------------------------------
*/

it('says which recipes lose the tag, and that they keep everything else', function () {
    $beef = BulkIngredient::factory()->create(['name' => 'Ground beef']);
    $chili = Recipe::factory()->withIngredients(2)->create(['name' => 'Chili']);
    $tacos = Recipe::factory()->create(['name' => 'White Boy Tacos']);
    $beef->recipes()->attach([$chili->id, $tacos->id]);

    Livewire::test(BulkIngredients::class)
        ->call('confirmRemove', $beef->id)
        ->assertSee('Chili, White Boy Tacos are tagged with it')
        ->assertSee('Those recipes keep every ingredient');
});

it('removes a pack and leaves the recipes standing', function () {
    $beef = BulkIngredient::factory()->create(['name' => 'Ground beef']);
    $chili = Recipe::factory()->withIngredients(2)->create(['name' => 'Chili']);
    $beef->recipes()->attach($chili);

    Livewire::test(BulkIngredients::class)
        ->call('confirmRemove', $beef->id)
        ->call('remove', $beef->id)
        ->assertSee('Ground beef is removed.')
        ->assertCount('rows', 0);

    expect(BulkIngredient::query()->count())->toBe(0)
        ->and($chili->fresh())->not->toBeNull()
        ->and($chili->fresh()->ingredients)->toHaveCount(2)
        ->and($chili->fresh()->bulkIngredients)->toHaveCount(0);
});

it('keeps the edits in the other rows when one row is removed', function () {
    $beef = BulkIngredient::factory()->create(['name' => 'Ground beef']);
    BulkIngredient::factory()->create(['name' => 'Tortillas', 'fridge_life_days' => 5]);

    Livewire::test(BulkIngredients::class)
        // An unsaved edit on the row that stays.
        ->set('rows.1.fridge_life_days', '7')
        ->call('confirmRemove', $beef->id)
        ->call('remove', $beef->id)
        ->assertCount('rows', 1)
        ->assertSet('rows.0.name', 'Tortillas')
        ->assertSet('rows.0.fridge_life_days', '7');
});
