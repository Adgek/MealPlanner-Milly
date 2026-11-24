<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Grocery\GroceryListBuilder;
use App\Livewire\RecipeEditor;
use App\Models\BulkIngredient;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipePrepStep;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The recipe editor (MM-15): every field AppPlan.md's recipe vault lists, so
 * everything the import in MM-5 will load can also be typed in by hand.
 *
 * Two things are worth the test time, the same two as MM-4's settings page.
 * First, that every field really saves — a form that silently drops the kid
 * off-ramp or an amount is worse than no form, because the household believes
 * the recipe is right. Second, that bad input is refused with something a person
 * can act on, and that a refused save writes nothing at all.
 */

/**
 * Every field filled in, so a field that fails to save shows up as an absence.
 *
 * @return array<string, mixed>
 */
function aFilledInRecipe(): array
{
    return [
        'name' => 'Beef Bulgogi',
        'cookableBy' => CookableBy::Partner->value,
        'effortTier' => EffortTier::Chaos15->value,
        'kidOffRamp' => 'Pull some plain beef and rice out before the sauce.',
        'ingredients' => [
            ['name' => 'Ground beef', 'quantity' => '1', 'unit' => 'lb', 'store_section' => 'Meat', 'pantry_basic' => false],
            ['name' => 'Soy sauce', 'quantity' => '60', 'unit' => 'ml', 'store_section' => 'Pantry', 'pantry_basic' => true],
        ],
        'prepSteps' => [
            ['description' => 'Marinate the beef', 'lead_time_hours' => '2'],
        ],
    ];
}

/**
 * The editor with a valid recipe in it, and one thing changed.
 *
 * @param  array<string, mixed>  $overrides
 */
function aRecipeForm(array $overrides = [], ?Recipe $recipe = null): Testable
{
    $component = $recipe === null
        ? Livewire::test(RecipeEditor::class)
        : Livewire::test(RecipeEditor::class, ['recipe' => $recipe]);

    $component->set(aFilledInRecipe());

    foreach ($overrides as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

/*
|--------------------------------------------------------------------------
| Opening it
|--------------------------------------------------------------------------
*/

it('opens a new recipe on the middle of each scale', function () {
    Livewire::test(RecipeEditor::class)
        ->assertOk()
        ->assertSet('recipeId', null)
        ->assertSet('cookableBy', CookableBy::Both->value)
        ->assertSet('effortTier', EffortTier::Workhorse30->value)
        // One empty ingredient row, so the shape of the thing is visible.
        ->assertCount('ingredients', 1)
        ->assertSee('Add a recipe');
});

it('opens with the recipe the household has', function () {
    $beef = BulkIngredient::factory()->create(['name' => 'Ground beef']);

    $recipe = Recipe::factory()->create([
        'name' => 'Chili',
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Project60,
        'kid_off_ramp' => 'Keep a bowl back before the chili powder.',
    ]);

    $recipe->bulkIngredients()->attach($beef);
    $recipe->ingredients()->create([
        'name' => 'Dried kidney beans',
        'quantity' => 400,
        'unit' => 'g',
        'store_section' => 'Dry goods',
        'pantry_basic' => false,
    ]);
    $recipe->prepSteps()->create(['description' => 'Soak the beans', 'lead_time_hours' => 24, 'position' => 0]);

    Livewire::test(RecipeEditor::class, ['recipe' => $recipe])
        ->assertOk()
        ->assertSet('recipeId', $recipe->id)
        ->assertSet('name', 'Chili')
        ->assertSet('cookableBy', 'me')
        ->assertSet('effortTier', '60_min_project')
        ->assertSet('kidOffRamp', 'Keep a bowl back before the chili powder.')
        ->assertSet('bulkIngredientIds', [$beef->id])
        ->assertSet('ingredients.0.name', 'Dried kidney beans')
        // "400.00" in the column reads as "400" in a form.
        ->assertSet('ingredients.0.quantity', '400')
        ->assertSet('prepSteps.0.description', 'Soak the beans')
        ->assertSet('prepSteps.0.lead_time_hours', '24');
});

it('renders the recipe’s values server-side, for the first paint', function () {
    $recipe = Recipe::factory()->create(['name' => 'Pupusas']);

    $this->get(route('recipes.edit', $recipe))
        ->assertOk()
        ->assertSee('value="Pupusas"', escape: false)
        ->assertSee('Save the recipe');
});

it('offers the cooks by the household’s own names', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, ['me' => 'Adrian', 'partner' => 'Milly']);

    Livewire::test(RecipeEditor::class)
        ->assertSee('Adrian')
        ->assertSee('Milly')
        ->assertSee('Both')
        ->assertDontSee('Partner');
});

it('says so when the recipe it is editing is out of the vault', function () {
    $recipe = Recipe::factory()->retired()->create();

    Livewire::test(RecipeEditor::class, ['recipe' => $recipe])
        ->assertSet('retired', true)
        ->assertSee('out of the vault');
});

/*
|--------------------------------------------------------------------------
| Saving
|--------------------------------------------------------------------------
*/

it('saves every field of a new recipe', function () {
    aRecipeForm()
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('recipes'));

    $recipe = Recipe::query()->firstWhere('name', 'Beef Bulgogi');

    expect($recipe)->not->toBeNull()
        ->and($recipe->cookable_by)->toBe(CookableBy::Partner)
        ->and($recipe->effort_tier)->toBe(EffortTier::Chaos15)
        ->and($recipe->kid_off_ramp)->toBe('Pull some plain beef and rice out before the sauce.')
        ->and($recipe->isRetired())->toBeFalse();
});

it('saves the ingredients with their amounts and units apart', function () {
    aRecipeForm()->call('save')->assertHasNoErrors();

    $ingredients = Recipe::query()->firstWhere('name', 'Beef Bulgogi')->ingredients;

    expect($ingredients)->toHaveCount(2)
        ->and($ingredients[0]->name)->toBe('Ground beef')
        ->and($ingredients[0]->quantity)->toBe(1.0)
        ->and($ingredients[0]->unit)->toBe('lb')
        ->and($ingredients[0]->store_section)->toBe('Meat')
        ->and($ingredients[0]->pantry_basic)->toBeFalse()
        ->and($ingredients[1]->quantity)->toBe(60.0)
        ->and($ingredients[1]->unit)->toBe('ml')
        ->and($ingredients[1]->pantry_basic)->toBeTrue();
});

it('saves an ingredient nobody measures', function () {
    aRecipeForm(['ingredients' => [
        ['name' => 'Soy sauce', 'quantity' => '', 'unit' => '', 'store_section' => '', 'pantry_basic' => false],
    ]])->call('save')->assertHasNoErrors();

    $ingredient = Recipe::query()->firstWhere('name', 'Beef Bulgogi')->ingredients->first();

    // Empty is null, not zero: "a splash of soy" has no amount, and 0 lb would
    // merge into the grocery list as an amount (MM-7).
    expect($ingredient->quantity)->toBeNull()
        ->and($ingredient->unit)->toBeNull()
        ->and($ingredient->store_section)->toBeNull();
});

it('saves the prep steps in the order they are shown', function () {
    aRecipeForm(['prepSteps' => [
        ['description' => 'Soak the beans', 'lead_time_hours' => '24'],
        ['description' => 'Dice the onions', 'lead_time_hours' => '0'],
    ]])->call('save')->assertHasNoErrors();

    $steps = Recipe::query()->firstWhere('name', 'Beef Bulgogi')->prepSteps;

    expect($steps->pluck('description')->all())->toBe(['Soak the beans', 'Dice the onions'])
        ->and($steps[0]->position)->toBe(0)
        ->and($steps[0]->lead_time_hours)->toBe(24)
        ->and($steps[0]->isAheadOfTheDay())->toBeTrue()
        ->and($steps[1]->position)->toBe(1)
        ->and($steps[1]->isAheadOfTheDay())->toBeFalse();
});

it('saves the bulk packs it splits', function () {
    $beef = BulkIngredient::factory()->create(['name' => 'Ground beef']);
    $tortillas = BulkIngredient::factory()->create(['name' => 'Tortillas']);

    aRecipeForm(['bulkIngredientIds' => [(string) $beef->id, (string) $tortillas->id]])
        ->call('save')
        ->assertHasNoErrors();

    expect(Recipe::query()->firstWhere('name', 'Beef Bulgogi')->bulkIngredients->pluck('name')->sort()->values()->all())
        ->toBe(['Ground beef', 'Tortillas']);
});

it('drops a tag the household unticked', function () {
    $beef = BulkIngredient::factory()->create(['name' => 'Ground beef']);
    $recipe = Recipe::factory()->create(['name' => 'Beef Bulgogi']);
    $recipe->bulkIngredients()->attach($beef);

    Livewire::test(RecipeEditor::class, ['recipe' => $recipe])
        ->set('bulkIngredientIds', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($recipe->fresh()->bulkIngredients)->toHaveCount(0)
        // The bulk ingredient itself is untouched: it is shared.
        ->and(BulkIngredient::query()->whereKey($beef->id)->exists())->toBeTrue();
});

it('rewrites the ingredients and prep steps of a recipe it edits', function () {
    $recipe = Recipe::factory()->withIngredients(3)->withPrepStep()->create(['name' => 'Chili']);

    Livewire::test(RecipeEditor::class, ['recipe' => $recipe])
        ->set('ingredients', [
            ['name' => 'Ground beef', 'quantity' => '2', 'unit' => 'lb', 'store_section' => 'Meat', 'pantry_basic' => false],
        ])
        ->set('prepSteps', [])
        ->call('save')
        ->assertHasNoErrors();

    expect($recipe->fresh()->ingredients->pluck('name')->all())->toBe(['Ground beef'])
        ->and($recipe->fresh()->ingredients->first()->quantity)->toBe(2.0)
        ->and($recipe->fresh()->prepSteps)->toHaveCount(0)
        // Rewritten, not duplicated.
        ->and(Ingredient::query()->where('ingredientable_id', $recipe->id)->count())->toBe(1)
        ->and(RecipePrepStep::query()->where('recipe_id', $recipe->id)->count())->toBe(0);
});

it('edits a recipe without adding a second one', function () {
    $recipe = Recipe::factory()->create(['name' => 'Chili']);

    Livewire::test(RecipeEditor::class, ['recipe' => $recipe])
        ->set('name', 'Chili con carne')
        ->call('save')
        ->assertHasNoErrors();

    expect(Recipe::query()->count())->toBe(1)
        ->and($recipe->fresh()->name)->toBe('Chili con carne');
});

it('throws away the rows nobody typed in', function () {
    Livewire::test(RecipeEditor::class)
        ->set('name', 'Quick Pasta')
        // The blank row a new recipe opens with, plus two the household added
        // and thought better of.
        ->call('addIngredient')
        ->call('addPrepStep')
        ->set('ingredients.1.name', 'Pasta')
        ->call('save')
        ->assertHasNoErrors();

    $recipe = Recipe::query()->firstWhere('name', 'Quick Pasta');

    expect($recipe->ingredients->pluck('name')->all())->toBe(['Pasta'])
        ->and($recipe->prepSteps)->toHaveCount(0);
});

it('adds and removes rows without saving anything', function () {
    Livewire::test(RecipeEditor::class)
        ->call('addIngredient')
        ->assertCount('ingredients', 2)
        ->call('removeIngredient', 0)
        ->assertCount('ingredients', 1)
        ->call('addPrepStep')
        ->call('addPrepStep')
        ->assertCount('prepSteps', 2)
        ->call('removePrepStep', 1)
        ->assertCount('prepSteps', 1);

    expect(Recipe::query()->count())->toBe(0);
});

it('trims what the household typed', function () {
    aRecipeForm([
        'name' => '  Beef Bulgogi ',
        'kidOffRamp' => "  Plain beef and rice.  \n",
        'ingredients.0.name' => '  Ground beef ',
    ])->call('save')->assertHasNoErrors();

    $recipe = Recipe::query()->firstWhere('name', 'Beef Bulgogi');

    expect($recipe)->not->toBeNull()
        ->and($recipe->kid_off_ramp)->toBe('Plain beef and rice.')
        ->and($recipe->ingredients->first()->name)->toBe('Ground beef');
});

it('says what it saved on the page it goes back to', function () {
    aRecipeForm()->call('save')->assertRedirect(route('recipes'));

    $this->get(route('recipes'))->assertOk()->assertSee('Beef Bulgogi is saved.');
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses a recipe with no name', function () {
    aRecipeForm(['name' => '  '])
        ->call('save')
        ->assertHasErrors('name')
        ->assertSee('Give the recipe a name.');

    expect(Recipe::query()->count())->toBe(0);
});

it('refuses a name another recipe already has', function () {
    Recipe::factory()->create(['name' => 'Beef Bulgogi']);

    aRecipeForm()
        ->call('save')
        ->assertHasErrors('name')
        ->assertSee('There is already a recipe with that name.');

    expect(Recipe::query()->count())->toBe(1);
});

it('lets a recipe keep its own name', function () {
    $recipe = Recipe::factory()->create(['name' => 'Beef Bulgogi']);

    aRecipeForm(recipe: $recipe)->call('save')->assertHasNoErrors();
});

it('refuses a name longer than a plan can print', function () {
    aRecipeForm(['name' => str_repeat('a', 121)])
        ->call('save')
        ->assertHasErrors('name')
        ->assertSee('Keep the name to 120 characters or fewer.');
});

it('refuses a cook or an effort tier it does not know', function (string $field, string $message) {
    aRecipeForm([$field => 'whenever'])
        ->call('save')
        ->assertHasErrors($field)
        ->assertSee($message);
})->with([
    ['cookableBy', 'Say who is willing to cook it.'],
    ['effortTier', 'Say how much cooking it is.'],
]);

it('refuses an ingredient row with an amount but no name', function () {
    aRecipeForm(['ingredients' => [
        ['name' => '', 'quantity' => '2', 'unit' => 'lb', 'store_section' => '', 'pantry_basic' => false],
    ]])
        ->call('save')
        ->assertHasErrors('ingredients.0.name')
        ->assertSee('Name the ingredient, or remove the row.');
});

it('refuses an amount that is not a number', function () {
    aRecipeForm(['ingredients.0.quantity' => 'a handful'])
        ->call('save')
        ->assertHasErrors('ingredients.0.quantity')
        ->assertSee('Give the amount as a number');
});

it('refuses an amount the column would round away', function () {
    // decimal(8,2): a third decimal place would be dropped silently.
    aRecipeForm(['ingredients.0.quantity' => '0.333'])
        ->call('save')
        ->assertHasErrors('ingredients.0.quantity')
        ->assertSee('two decimal places');
});

it('refuses an amount that is not an amount', function (string $value) {
    // Zero is not "no amount", it is a line on the grocery list that says to buy
    // nothing: "Cumin — 0 tsp". Leaving the box empty is how you say you do not
    // measure it.
    aRecipeForm(['ingredients.0.quantity' => $value])
        ->call('save')
        ->assertHasErrors('ingredients.0.quantity')
        ->assertSee('An amount has to be more than zero.');
})->with([
    'zero' => ['0'],
    'nothing at all' => ['0.00'],
    'negative' => ['-1'],
]);

it('refuses the same ingredient twice on one recipe', function () {
    // Two identical rows merge into one line of double the amount on the grocery
    // list (MM-7), with nothing on the recipe saying why.
    aRecipeForm(['ingredients' => [
        ['name' => 'Ground beef', 'quantity' => '1', 'unit' => 'lb', 'store_section' => 'Meat', 'pantry_basic' => false],
        ['name' => ' ground beef ', 'quantity' => '1', 'unit' => 'lb', 'store_section' => '', 'pantry_basic' => false],
    ]])
        ->call('save')
        ->assertHasErrors('ingredients.1.name')
        ->assertSee('That ingredient is on this recipe twice. Put the whole amount on one row.');

    expect(Recipe::query()->count())->toBe(0);
});

it('allows the same ingredient in two different units', function () {
    // "2 clove" and "1 tbsp" of garlic are two different things to buy, and the
    // grocery list keeps them as two lines (MM-7).
    aRecipeForm(['ingredients' => [
        ['name' => 'Garlic', 'quantity' => '2', 'unit' => 'clove', 'store_section' => 'Produce', 'pantry_basic' => false],
        ['name' => 'Garlic', 'quantity' => '1', 'unit' => 'tbsp', 'store_section' => 'Pantry', 'pantry_basic' => true],
    ]])->call('save')->assertHasNoErrors();

    expect(Recipe::query()->firstWhere('name', 'Beef Bulgogi')->ingredients)->toHaveCount(2);
});

it('refuses an amount bigger than the column holds', function () {
    aRecipeForm(['ingredients.0.quantity' => '1000000'])
        ->call('save')
        ->assertHasErrors('ingredients.0.quantity');
});

it('refuses a unit or a store section too long to read', function (string $field, string $message) {
    aRecipeForm([$field => str_repeat('x', 200)])
        ->call('save')
        ->assertHasErrors($field)
        ->assertSee($message);
})->with([
    ['ingredients.0.unit', 'Keep the unit short'],
    ['ingredients.0.store_section', 'Keep the store section to 60 characters or fewer.'],
]);

it('refuses a prep step with a lead time but no description', function () {
    aRecipeForm(['prepSteps' => [['description' => '', 'lead_time_hours' => '24']]])
        ->call('save')
        ->assertHasErrors('prepSteps.0.description')
        ->assertSee('Say what the prep step is, or remove the row.');
});

it('refuses a lead time that is not a whole number of hours', function (string $value, string $message) {
    aRecipeForm(['prepSteps.0.lead_time_hours' => $value])
        ->call('save')
        ->assertHasErrors('prepSteps.0.lead_time_hours')
        ->assertSee($message);
})->with([
    'empty' => ['', 'Say how many hours before dinner it happens.'],
    'words' => ['overnight', 'Give the lead time as a whole number of hours.'],
    'half an hour' => ['1.5', 'Give the lead time as a whole number of hours.'],
    'negative' => ['-1', 'A lead time cannot be negative.'],
    'a fortnight' => ['400', 'A week ahead is as far as a prep reminder goes.'],
]);

it('refuses a bulk ingredient that no longer exists', function () {
    aRecipeForm(['bulkIngredientIds' => ['999']])
        ->call('save')
        ->assertHasErrors('bulkIngredientIds.0')
        ->assertSee('no longer exists');
});

it('refuses a kid off-ramp longer than a note', function () {
    aRecipeForm(['kidOffRamp' => str_repeat('a', 1001)])
        ->call('save')
        ->assertHasErrors('kidOffRamp')
        ->assertSee('Keep the kid off-ramp to 1000 characters or fewer.');
});

it('says plainly that nothing was saved', function () {
    aRecipeForm(['name' => ''])
        ->call('save')
        ->assertSee('Nothing was saved.')
        ->assertNoRedirect();
});

it('writes nothing at all when one row is wrong', function () {
    $recipe = Recipe::factory()->create([
        'name' => 'Chili',
        'cookable_by' => CookableBy::Me,
        'effort_tier' => EffortTier::Project60,
        'kid_off_ramp' => 'Keep a bowl back.',
    ]);
    $recipe->ingredients()->create(['name' => 'Dried kidney beans', 'quantity' => 400, 'unit' => 'g']);
    $recipe->prepSteps()->create(['description' => 'Soak the beans', 'lead_time_hours' => 24, 'position' => 0]);

    // Everything on the form is new and valid except one amount.
    aRecipeForm(['ingredients.1.quantity' => 'a splash'], recipe: $recipe)
        ->call('save')
        ->assertHasErrors('ingredients.1.quantity');

    $recipe->refresh()->load(['ingredients', 'prepSteps']);

    expect($recipe->name)->toBe('Chili')
        ->and($recipe->cookable_by)->toBe(CookableBy::Me)
        ->and($recipe->effort_tier)->toBe(EffortTier::Project60)
        ->and($recipe->kid_off_ramp)->toBe('Keep a bowl back.')
        ->and($recipe->ingredients->pluck('name')->all())->toBe(['Dried kidney beans'])
        ->and($recipe->prepSteps->pluck('description')->all())->toBe(['Soak the beans']);
});

/*
|--------------------------------------------------------------------------
| What an edit reaches
|--------------------------------------------------------------------------
*/

it('leaves the grocery list to catch up with an ingredient change itself', function () {
    // Sunday 11 January 2026 is a shopping day, so the next window is Mon-Wed.
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

    expect($groceries->isStale($plan))->toBeFalse();

    // The household doubles the beef on a recipe that is on the plan.
    Livewire::test(RecipeEditor::class, ['recipe' => $chili])
        ->set('ingredients.0.quantity', '2')
        ->call('save')
        ->assertHasNoErrors();

    // The editor does not touch the stored list: it is shared, and somebody may
    // be holding it in the shop. The list catches up when it is next read
    // through currentFor(), which is the contract MM-7 set for MM-8.
    expect($groceries->isStale($plan->fresh()))->toBeTrue()
        ->and($groceries->listFor($plan->fresh())->lineFor('Ground beef', 'lb')->quantity)->toBe(1.0)
        ->and($groceries->currentFor($plan->fresh())->lineFor('Ground beef', 'lb')->quantity)->toBe(2.0);

    Carbon::setTestNow();
});
