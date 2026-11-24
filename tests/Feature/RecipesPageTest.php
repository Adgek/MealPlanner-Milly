<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Livewire\Recipes;
use App\Models\BulkIngredient;
use App\Models\MealHistoryEntry;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Settings\HouseholdSettings;
use Livewire\Livewire;

/*
 * The recipe list (MM-15), and the one dangerous thing on it: removing a recipe.
 *
 * The data layer is tested in tests/Feature/Vault/RetirementTest.php. These are
 * about the screen: that the household is told the truth before they press
 * anything, that the button they are offered is the one that cannot destroy what
 * they have cooked, and that the plan is left coherent afterwards.
 */

/**
 * A date relative to today on the kitchen clock, as a plan night uses it.
 */
function aVaultNight(int $daysFromToday): string
{
    return (new MealPlanner)->today()->addDays($daysFromToday)->toDateString();
}

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

it('shows the vault with what the picker matches on', function () {
    $chili = Recipe::factory()->withIngredients(2)->withPrepStep()->create([
        'name' => 'Chili',
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Project60,
    ]);
    $chili->bulkIngredients()->attach(BulkIngredient::factory()->create(['name' => 'Ground beef']));

    $this->get(route('recipes'))
        ->assertOk()
        ->assertSee('Chili')
        ->assertSee('60-min+ project')
        ->assertSee('2 ingredients')
        ->assertSee('1 prep step')
        ->assertSee('splits Ground beef');
});

it('offers the way in: add, edit and the bulk ingredients', function () {
    $recipe = Recipe::factory()->create();

    $this->get(route('recipes'))
        ->assertOk()
        ->assertSee('href="'.route('recipes.new').'"', escape: false)
        ->assertSee('href="'.route('recipes.edit', $recipe).'"', escape: false)
        ->assertSee('href="'.route('recipes.bulk-ingredients').'"', escape: false);
});

it('calls the cooks by the household’s own names', function () {
    // Anything showing a cook to a person goes through HouseholdSettings, so
    // renaming one in Settings renames them here too (MM-4).
    HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, ['me' => 'Adrian', 'partner' => 'Milly']);

    Recipe::factory()->cookableBy(CookableBy::Me)->create(['name' => 'Chili']);
    Recipe::factory()->cookableBy(CookableBy::Partner)->create(['name' => 'Pupusas']);
    Recipe::factory()->cookableBy(CookableBy::Both)->create(['name' => 'Tacos']);

    $this->get(route('recipes'))
        ->assertOk()
        ->assertSee('Adrian')
        ->assertSee('Milly')
        // "Both" is not a person, so it keeps the tag's own label.
        ->assertSee('Both')
        ->assertDontSee('Partner');
});

it('says what an empty vault means', function () {
    $this->get(route('recipes'))
        ->assertOk()
        ->assertSee('No recipes yet');
});

it('keeps what is out of the vault in its own list', function () {
    Recipe::factory()->create(['name' => 'Chili']);
    Recipe::factory()->retired()->create(['name' => 'Pupusas']);

    Livewire::test(Recipes::class)
        ->assertSee('In the vault')
        ->assertSee('Out of the vault')
        ->assertSee('Pupusas')
        ->assertSeeInOrder(['In the vault', 'Chili', 'Out of the vault', 'Pupusas']);
});

/*
|--------------------------------------------------------------------------
| What the household is told before removing one
|--------------------------------------------------------------------------
*/

it('names the night a removal would change', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    $plan = Plan::factory()->create();
    $night = PlanNight::factory()->for($plan)->on(aVaultNight(2))->withRecipe($chili)->create();

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->assertSee('It is dinner on '.$night->date->format('D j M'))
        ->assertSee('picks something else for that night');
});

it('promises the cooking history survives, and offers no way to destroy it', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    foreach ([2, 9, 16] as $daysAgo) {
        MealHistoryEntry::factory()->create([
            'recipe_id' => $chili->id,
            'cooked_on' => aVaultNight(-$daysAgo),
        ]);
    }

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->assertSee('You have cooked it 3 times')
        ->assertSee('Removing it keeps that record')
        ->assertSee('You have eaten this, and deleting it would take that record with it.')
        // The destructive button is not even on the page.
        ->assertDontSee('Delete it for good');
});

it('offers a real delete for a recipe typed by mistake', function () {
    $mistake = Recipe::factory()->create(['name' => 'Chlii']);

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $mistake->id)
        ->assertSee('Delete it for good')
        ->call('deleteForGood', $mistake->id)
        ->assertSee('Chlii is deleted.');

    expect(Recipe::query()->count())->toBe(0);
});

it('lets the question be dropped', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->assertSet('removing', $chili->id)
        ->call('cancelRemove')
        ->assertSet('removing', null);

    expect($chili->fresh()->isRetired())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Removing one
|--------------------------------------------------------------------------
*/

it('takes a recipe out of the vault and says what that did to the plan', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    $pasta = Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    $night = PlanNight::factory()->for($plan)->on(aVaultNight(1))->withRecipe($chili)->create();

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->call('retire', $chili->id)
        ->assertSee('Chili is out of the vault')
        ->assertSee($night->date->format('D j M').' now has a different dinner.')
        ->assertSet('removing', null);

    expect($chili->fresh()->isRetired())->toBeTrue()
        ->and($night->fresh()->recipe_id)->toBe($pasta->id);
});

it('keeps the history and the past night when it removes a recipe that has been cooked', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    MealHistoryEntry::factory()->create(['recipe_id' => $chili->id, 'cooked_on' => aVaultNight(-3)]);

    $plan = Plan::factory()->create();
    $past = PlanNight::factory()->for($plan)->on(aVaultNight(-3))->withRecipe($chili)->create();

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->call('retire', $chili->id)
        ->assertSee('Nothing you have cooked was lost.');

    expect(Recipe::query()->whereKey($chili->id)->exists())->toBeTrue()
        ->and(MealHistoryEntry::query()->where('recipe_id', $chili->id)->count())->toBe(1)
        ->and($past->fresh()->recipe_id)->toBe($chili->id);
});

it('never claims a dinner changed when nothing else fitted', function () {
    // The only recipe in the vault is the one being removed, so the re-pick has
    // nothing to put on the night and MM-6 keeps the meal there.
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    $plan = Plan::factory()->create();
    $night = PlanNight::factory()->for($plan)->on(aVaultNight(1))->withRecipe($chili)->create();

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->call('retire', $chili->id)
        ->assertDontSee('now has a different dinner')
        ->assertSee($night->date->format('D j M').' kept it')
        ->assertSee('Add a recipe that fits');

    // The banner and the plan agree: the night still has chili on it.
    expect($night->fresh()->recipe_id)->toBe($chili->id)
        ->and($night->fresh()->pick_note)->toBe('Kept Chili: nothing else fits.');
});

it('reports only the nights that really changed', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    $pasta = Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    $monday = PlanNight::factory()->for($plan)->on(aVaultNight(1))->withRecipe($chili)->create();
    // One alternative in the vault, and it can only be on one night of a window.
    $tuesday = PlanNight::factory()->for($plan)->on(aVaultNight(2))->withRecipe($chili)->create();

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->call('retire', $chili->id)
        ->assertSee($monday->date->format('D j M').' now has a different dinner.')
        ->assertSee($tuesday->date->format('D j M').' kept it');

    expect($monday->fresh()->recipe_id)->toBe($pasta->id)
        ->and($tuesday->fresh()->recipe_id)->toBe($chili->id);
});

it('leaves a locked night its dinner, and says so', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    Recipe::factory()->effort(EffortTier::Chaos15)->cookableBy(CookableBy::Both)->create();

    $plan = Plan::factory()->create();
    $friday = PlanNight::factory()->for($plan)->on(aVaultNight(3))->withRecipe($chili)->locked()->create();

    Livewire::test(Recipes::class)
        ->call('confirmRemove', $chili->id)
        ->assertSee('is locked, so it keeps this meal')
        ->call('retire', $chili->id)
        ->assertSee($friday->date->format('D j M').' keeps it');

    expect($friday->fresh()->recipe_id)->toBe($chili->id);
});

it('brings a recipe back', function () {
    $chili = Recipe::factory()->retired()->create(['name' => 'Chili']);

    Livewire::test(Recipes::class)
        ->call('bringBack', $chili->id)
        ->assertSee('Chili is back in the vault');

    expect($chili->fresh()->isRetired())->toBeFalse();
});

it('refuses a delete that would destroy history, even from a stale page', function () {
    // The page was opened when nothing pointed at the recipe; by the time the
    // button is pressed, the household has cooked it.
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    $page = Livewire::test(Recipes::class)->call('confirmRemove', $chili->id);

    MealHistoryEntry::factory()->create(['recipe_id' => $chili->id, 'cooked_on' => aVaultNight(-1)]);

    $page->call('deleteForGood', $chili->id)->assertSee('Chili was kept.');

    expect(Recipe::query()->whereKey($chili->id)->exists())->toBeTrue()
        ->and(MealHistoryEntry::query()->where('recipe_id', $chili->id)->count())->toBe(1)
        // Nothing was written at all: the household asked for the destructive
        // one, so the safe one is offered rather than done behind their back.
        ->and($chili->fresh()->isRetired())->toBeFalse();
});

it('shrugs at a recipe somebody else has already removed', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);
    $page = Livewire::test(Recipes::class)->call('confirmRemove', $chili->id);

    $chili->delete();

    $page->call('retire', $chili->id)->assertSee('already gone')->assertOk();
});
