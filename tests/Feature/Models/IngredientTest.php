<?php

use App\Models\Ingredient;
use App\Models\KitchenProject;
use App\Models\Recipe;

/*
 * Ingredients are polymorphic: the same table serves recipes and kitchen
 * projects, so the grocery list (MM-7) reads both in one pass.
 */

it('creates an ingredient for a recipe and reads it back', function () {
    $recipe = Recipe::factory()->create();

    $recipe->ingredients()->create([
        'name' => 'Ground beef',
        'quantity' => 1.5,
        'unit' => 'lb',
        'store_section' => 'Meat',
        'pantry_basic' => false,
    ]);

    $ingredient = Ingredient::firstWhere('name', 'Ground beef');

    expect($ingredient->quantity)->toBe(1.5)
        ->and($ingredient->unit)->toBe('lb')
        ->and($ingredient->store_section)->toBe('Meat')
        ->and($ingredient->pantry_basic)->toBeFalse()
        ->and($ingredient->ingredientable)->toBeInstanceOf(Recipe::class)
        ->and($ingredient->ingredientable->is($recipe))->toBeTrue();
});

it('creates an ingredient for a kitchen project and reads it back', function () {
    $project = KitchenProject::factory()->create();

    $project->ingredients()->create(['name' => 'Carrots', 'quantity' => 500, 'unit' => 'g']);

    $ingredient = Ingredient::firstWhere('name', 'Carrots');

    expect($ingredient->ingredientable)->toBeInstanceOf(KitchenProject::class)
        ->and($ingredient->ingredientable->is($project))->toBeTrue();
});

it('keeps recipe and project ingredients apart', function () {
    $recipe = Recipe::factory()->create();
    $project = KitchenProject::factory()->create();
    $recipe->ingredients()->create(['name' => 'Beef']);
    $project->ingredients()->create(['name' => 'Carrots']);

    expect($recipe->refresh()->ingredients->pluck('name')->all())->toBe(['Beef'])
        ->and($project->refresh()->ingredients->pluck('name')->all())->toBe(['Carrots']);
});

it('returns the amount as a number so the list can add it up', function () {
    $recipe = Recipe::factory()->create();
    $recipe->ingredients()->createMany([
        ['name' => 'Ground beef', 'quantity' => 1, 'unit' => 'lb'],
        ['name' => 'Ground beef', 'quantity' => 1, 'unit' => 'lb'],
    ]);

    // "1 lb + 1 lb ground beef" has to become "2 lb" (docs/decisions.md), which a
    // string from the decimal cast would silently concatenate instead.
    $total = $recipe->refresh()->ingredients->sum('quantity');

    expect($total)->toBe(2.0);
});

it('records an ingredient nobody measures', function () {
    $recipe = Recipe::factory()->create();

    $recipe->ingredients()->create(['name' => 'Splash of soy sauce']);

    $ingredient = Ingredient::firstWhere('name', 'Splash of soy sauce');

    expect($ingredient->quantity)->toBeNull()
        ->and($ingredient->unit)->toBeNull();
});

it('flags a pantry basic for the short check-you-have section', function () {
    $recipe = Recipe::factory()->create();
    $recipe->ingredients()->create(['name' => 'Olive oil', 'pantry_basic' => true]);
    $recipe->ingredients()->create(['name' => 'Lettuce', 'pantry_basic' => false]);

    expect(Ingredient::where('pantry_basic', true)->pluck('name')->all())->toBe(['Olive oil']);
});
