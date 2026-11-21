<?php

use App\Models\FreezerItem;
use App\Settings\HouseholdSettings;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * The freezer stash: the one thing the app counts (docs/decisions.md).
 */

it('creates a freezer item and reads it back', function () {
    FreezerItem::create([
        'name' => 'Frozen minestrone',
        'portions_left' => 4,
        'thaw_lead_time_hours' => 24,
        'kid_off_ramp' => 'Strain a portion so it is mostly pasta and broth.',
    ]);

    $item = FreezerItem::firstWhere('name', 'Frozen minestrone');

    expect($item->portions_left)->toBe(4)
        ->and($item->thaw_lead_time_hours)->toBe(24)
        ->and($item->kid_off_ramp)->toContain('Strain a portion')
        ->and($item->isAvailable())->toBeTrue();
});

it('starts at no portions by default', function () {
    // A row with nothing in it is normal: the item is known, the stash is empty.
    $item = FreezerItem::create(['name' => 'Frozen chili']);

    expect($item->refresh()->portions_left)->toBe(0)
        ->and($item->isAvailable())->toBeFalse();
});

it('counts a portion down when a night uses one', function () {
    $item = FreezerItem::factory()->portions(3)->create();

    $item->decrement('portions_left');

    expect($item->refresh()->portions_left)->toBe(2);
});

it('counts portions up when a project restocks it', function () {
    $item = FreezerItem::factory()->portions(1)->create();

    $item->increment('portions_left', 6);

    expect($item->refresh()->portions_left)->toBe(7);
});

it('knows when the stash is running low', function () {
    // The starting warning level is 2 portions (docs/decisions.md).
    expect(HouseholdSettings::lowFreezerStashPortions())->toBe(2);

    expect(FreezerItem::factory()->portions(2)->create()->isRunningLow())->toBeTrue()
        ->and(FreezerItem::factory()->portions(3)->create()->isRunningLow())->toBeFalse();
});

it('follows the warning level the household sets', function () {
    $item = FreezerItem::factory()->portions(3)->create();

    HouseholdSettings::set(HouseholdSettings::KEY_LOW_FREEZER_STASH_PORTIONS, 4);

    expect($item->isRunningLow())->toBeTrue();
});

it('refuses two freezer items with the same name', function () {
    FreezerItem::factory()->create(['name' => 'Frozen minestrone']);

    expect(fn () => FreezerItem::factory()->create(['name' => 'Frozen minestrone']))
        ->toThrow(UniqueConstraintViolationException::class);
});
