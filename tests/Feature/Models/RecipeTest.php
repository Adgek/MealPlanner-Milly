<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Models\BulkIngredient;
use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * The recipe vault: the table the picker (MM-6) filters, and the one everything
 * else hangs off.
 */

it('creates a recipe and reads it back', function () {
    Recipe::create([
        'name' => 'Beef Bulgogi',
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Workhorse30,
        'kid_off_ramp' => 'Plain beef and rice before the sauce.',
    ]);

    $recipe = Recipe::firstWhere('name', 'Beef Bulgogi');

    expect($recipe->cookable_by)->toBe(CookableBy::Both)
        ->and($recipe->effort_tier)->toBe(EffortTier::Workhorse30)
        ->and($recipe->kid_off_ramp)->toBe('Plain beef and rice before the sauce.');
});

it('keeps who-can-cook-it and how-long-it-takes as enums, not loose strings', function () {
    $recipe = Recipe::factory()->create([
        'cookable_by' => CookableBy::Partner,
        'effort_tier' => EffortTier::Chaos15,
    ]);

    // Round-tripped through MySQL, not just held in memory.
    $recipe->refresh();

    expect($recipe->cookable_by)->toBeInstanceOf(CookableBy::class)
        ->and($recipe->effort_tier)->toBeInstanceOf(EffortTier::class)
        ->and($recipe->effort_tier->minutes())->toBe(15);
});

it('refuses two recipes with the same name', function () {
    Recipe::factory()->create(['name' => 'Chili']);

    // So the import in MM-5 can run twice without duplicating the vault.
    expect(fn () => Recipe::factory()->create(['name' => 'Chili']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows a recipe with no kid off-ramp', function () {
    $recipe = Recipe::factory()->create(['kid_off_ramp' => null]);

    expect($recipe->refresh()->kid_off_ramp)->toBeNull();
});

it('reads back its ingredients', function () {
    $recipe = Recipe::factory()->create();
    $recipe->ingredients()->createMany([
        ['name' => 'Ground beef', 'quantity' => 1, 'unit' => 'lb', 'store_section' => 'Meat'],
        ['name' => 'Soy sauce', 'quantity' => 60, 'unit' => 'ml', 'store_section' => 'Pantry', 'pantry_basic' => true],
    ]);

    $ingredients = $recipe->refresh()->ingredients;

    expect($ingredients)->toHaveCount(2)
        ->and($ingredients->firstWhere('name', 'Ground beef')->quantity)->toBe(1.0)
        ->and($ingredients->firstWhere('name', 'Soy sauce')->pantry_basic)->toBeTrue();
});

it('reads back its prep steps in order', function () {
    $recipe = Recipe::factory()->create();
    // Created out of order on purpose: the relation sorts, the insert order doesn't.
    $recipe->prepSteps()->create(['description' => 'Dice the onions', 'lead_time_hours' => 0, 'position' => 1]);
    $recipe->prepSteps()->create(['description' => 'Soak the beans', 'lead_time_hours' => 24, 'position' => 0]);

    $steps = $recipe->refresh()->prepSteps;

    expect($steps->pluck('description')->all())->toBe(['Soak the beans', 'Dice the onions'])
        ->and($steps->first()->isAheadOfTheDay())->toBeTrue()
        ->and($steps->last()->isAheadOfTheDay())->toBeFalse();
});

it('shares a bulk ingredient with another recipe', function () {
    $groundBeef = BulkIngredient::factory()->create(['name' => 'Ground beef']);
    $bulgogi = Recipe::factory()->create(['name' => 'Beef Bulgogi']);
    $tacos = Recipe::factory()->create(['name' => 'White Boy Tacos']);

    $bulgogi->bulkIngredients()->attach($groundBeef);
    $tacos->bulkIngredients()->attach($groundBeef);

    // The pair MM-11 looks for: two recipes that can split one pack.
    expect($groundBeef->refresh()->recipes->pluck('name')->sort()->values()->all())
        ->toBe(['Beef Bulgogi', 'White Boy Tacos'])
        ->and($bulgogi->refresh()->bulkIngredients->pluck('name')->all())->toBe(['Ground beef']);
});

it('carries more than one bulk ingredient', function () {
    $recipe = Recipe::factory()->create();
    $recipe->bulkIngredients()->attach([
        BulkIngredient::factory()->create(['name' => 'Ground beef'])->id,
        BulkIngredient::factory()->create(['name' => 'Tortillas'])->id,
    ]);

    expect($recipe->refresh()->bulkIngredients)->toHaveCount(2);
});

it('does not pair a bulk ingredient with the same recipe twice', function () {
    $recipe = Recipe::factory()->create();
    $ingredient = BulkIngredient::factory()->create();
    $recipe->bulkIngredients()->attach($ingredient);

    expect(fn () => $recipe->bulkIngredients()->attach($ingredient))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('takes its ingredients and prep steps with it when deleted', function () {
    $recipe = Recipe::factory()->withIngredients(2)->withPrepStep()->create();
    $recipe->bulkIngredients()->attach(BulkIngredient::factory()->create());

    $recipe->delete();

    // Orphans here would show up as blank lines on a grocery list.
    expect(Ingredient::count())->toBe(0)
        ->and(DB::table('recipe_prep_steps')->count())->toBe(0)
        ->and(DB::table('bulk_ingredient_recipe')->count())->toBe(0)
        // The bulk ingredient itself is shared, so it survives.
        ->and(BulkIngredient::count())->toBe(1);
});

it('builds a partner solo-night recipe from the factory', function () {
    $recipe = Recipe::factory()->partnerSolo()->create();

    expect($recipe->cookable_by)->toBe(CookableBy::Partner)
        ->and($recipe->effort_tier)->toBe(EffortTier::Chaos15);
});
