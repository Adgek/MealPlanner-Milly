<?php

use App\Models\KidBackupFood;
use Illuminate\Database\UniqueConstraintViolationException;

/*
 * The kid off-ramp inventory: the safe backups to keep stocked alongside the
 * regular groceries (AppPlan.md, section 3).
 */

it('creates a kid backup food and reads it back', function () {
    KidBackupFood::create(['name' => 'Chicken nuggets', 'quantity' => 1, 'unit' => 'bag']);

    $food = KidBackupFood::firstWhere('name', 'Chicken nuggets');

    expect($food->quantity)->toBe(1.0)
        ->and($food->unit)->toBe('bag');
});

it('uses the kid_backup_foods table', function () {
    // Laravel treats "food" as uncountable and would look for `kid_backup_food`.
    expect((new KidBackupFood)->getTable())->toBe('kid_backup_foods');
});

it('records an amount the grocery list can merge', function () {
    KidBackupFood::factory()->create(['name' => 'Bread', 'quantity' => 1, 'unit' => 'loaf']);
    KidBackupFood::factory()->create(['name' => 'Yogurt tubes', 'quantity' => 2, 'unit' => 'box']);

    expect(KidBackupFood::sum('quantity'))->toEqual(3.0);
});

it('allows a food with no amount', function () {
    $food = KidBackupFood::create(['name' => 'Apple sauce']);

    expect($food->refresh()->quantity)->toBeNull();
});

it('refuses two foods with the same name', function () {
    KidBackupFood::factory()->create(['name' => 'Bread']);

    expect(fn () => KidBackupFood::factory()->create(['name' => 'Bread']))
        ->toThrow(UniqueConstraintViolationException::class);
});
