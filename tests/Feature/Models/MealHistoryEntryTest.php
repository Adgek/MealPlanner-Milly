<?php

use App\Models\MealHistoryEntry;
use App\Models\Recipe;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/*
 * Meal history: what we actually cooked. This is what variety is measured
 * against — "favour recipes that haven't been cooked recently".
 */

it('creates a history entry and reads it back', function () {
    $chili = Recipe::factory()->create(['name' => 'Chili']);

    MealHistoryEntry::create(['cooked_on' => '2026-01-14', 'recipe_id' => $chili->id]);

    $entry = MealHistoryEntry::first();

    expect($entry->cooked_on->toDateString())->toBe('2026-01-14')
        ->and($entry->recipe->name)->toBe('Chili');
});

it('uses the meal_history table', function () {
    expect((new MealHistoryEntry)->getTable())->toBe('meal_history');
});

it('reads a recipe history back off the recipe', function () {
    $recipe = Recipe::factory()->create();
    MealHistoryEntry::factory()->for($recipe)->daysAgo(3)->create();
    MealHistoryEntry::factory()->for($recipe)->daysAgo(40)->create();

    expect($recipe->refresh()->mealHistory)->toHaveCount(2);
});

it('finds what was cooked recently', function () {
    $recent = Recipe::factory()->create(['name' => 'Quick Pasta']);
    $forgotten = Recipe::factory()->create(['name' => 'Chili']);
    MealHistoryEntry::factory()->for($recent)->daysAgo(3)->create();
    MealHistoryEntry::factory()->for($forgotten)->daysAgo(60)->create();

    $lastFortnight = MealHistoryEntry::query()
        ->since(Carbon::now()->subDays(14))
        ->with('recipe')
        ->get();

    expect($lastFortnight->pluck('recipe.name')->all())->toBe(['Quick Pasta']);
});

it('records the same recipe on two different days', function () {
    $recipe = Recipe::factory()->create();

    MealHistoryEntry::factory()->for($recipe)->on('2026-01-14')->create();
    MealHistoryEntry::factory()->for($recipe)->on('2026-01-21')->create();

    expect(MealHistoryEntry::count())->toBe(2);
});

it('does not record the same recipe twice on one day', function () {
    $recipe = Recipe::factory()->create();
    MealHistoryEntry::factory()->for($recipe)->on('2026-01-14')->create();

    expect(fn () => MealHistoryEntry::factory()->for($recipe)->on('2026-01-14')->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('drops the history when the recipe is deleted', function () {
    $recipe = Recipe::factory()->create();
    MealHistoryEntry::factory()->for($recipe)->create();

    $recipe->delete();

    expect(MealHistoryEntry::count())->toBe(0);
});
