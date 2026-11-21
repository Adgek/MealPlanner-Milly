<?php

use App\Models\BulkIngredient;
use App\Settings\HouseholdSettings;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * Bulk ingredients, and the fridge-life fallback: "if it is not set, the Settings
 * default applies".
 */

it('creates a bulk ingredient and reads it back', function () {
    BulkIngredient::create(['name' => 'Ground beef', 'fridge_life_days' => 2]);

    $ingredient = BulkIngredient::firstWhere('name', 'Ground beef');

    expect($ingredient->fridge_life_days)->toBe(2)
        ->and($ingredient->fridgeLifeDays())->toBe(2);
});

it('falls back to the Settings default when it has no fridge life of its own', function () {
    $ingredient = BulkIngredient::factory()->withoutFridgeLife()->create();

    expect($ingredient->fridge_life_days)->toBeNull()
        // The starting default from docs/decisions.md.
        ->and($ingredient->fridgeLifeDays())->toBe(2);
});

it('follows the Settings default when the household changes it', function () {
    $ingredient = BulkIngredient::factory()->withoutFridgeLife()->create();

    HouseholdSettings::set(HouseholdSettings::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS, 4);

    expect($ingredient->fridgeLifeDays())->toBe(4);
});

it('prefers its own fridge life over the Settings default', function () {
    $ingredient = BulkIngredient::factory()->fridgeLifeDays(1)->create();

    HouseholdSettings::set(HouseholdSettings::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS, 7);

    // Chicken does not keep as long as the household's general rule.
    expect($ingredient->fridgeLifeDays())->toBe(1);
});

it('refuses two bulk ingredients with the same name', function () {
    BulkIngredient::factory()->create(['name' => 'Ground beef']);

    expect(fn () => BulkIngredient::factory()->create(['name' => 'Ground beef']))
        ->toThrow(UniqueConstraintViolationException::class);
});
