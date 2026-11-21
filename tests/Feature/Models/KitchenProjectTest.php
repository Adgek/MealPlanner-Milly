<?php

use App\Models\FreezerItem;
use App\Models\KitchenProject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/*
 * Recurring kitchen projects, and the "every X days" cadence MM-12 reads to
 * decide one is due.
 */

it('creates a kitchen project and reads it back', function () {
    $soup = FreezerItem::factory()->create(['name' => 'Frozen minestrone']);

    KitchenProject::create([
        'name' => 'Big batch minestrone',
        'every_days' => 21,
        'produces_freezer_item_id' => $soup->id,
        'produces_portions' => 6,
        'last_done_on' => '2026-01-04',
    ]);

    $project = KitchenProject::firstWhere('name', 'Big batch minestrone');

    expect($project->every_days)->toBe(21)
        ->and($project->produces_portions)->toBe(6)
        ->and($project->last_done_on->toDateString())->toBe('2026-01-04')
        ->and($project->producesFreezerItem->is($soup))->toBeTrue();
});

it('records a project that stocks the fridge rather than the freezer', function () {
    // Stock, sauces and hummus produce nothing countable.
    $project = KitchenProject::factory()->create([
        'name' => 'Fresh hummus',
        'produces_freezer_item_id' => null,
        'produces_portions' => null,
    ]);

    expect($project->refresh()->producesFreezerItem)->toBeNull()
        ->and($project->produces_portions)->toBeNull();
});

it('is due every X days after it was last done', function () {
    $project = KitchenProject::factory()->create([
        'every_days' => 21,
        'last_done_on' => '2026-01-04',
    ]);

    expect($project->dueOn()->toDateString())->toBe('2026-01-25');
});

it('is due now when it has never been done', function () {
    // So a freshly imported list of projects shows up in the first plan.
    $project = KitchenProject::factory()->neverDone()->create();

    expect($project->dueOn(Carbon::parse('2026-02-01'))->toDateString())->toBe('2026-02-01');
});

it('keeps the last-done date as a calendar date, with no time attached', function () {
    $project = KitchenProject::factory()->lastDoneOn('2026-03-08')->create();

    // A DATE column: it cannot slide to the 7th the way a UTC timestamp would.
    expect($project->refresh()->last_done_on->toDateString())->toBe('2026-03-08')
        ->and($project->last_done_on->format('H:i:s'))->toBe('00:00:00');
});

it('reads back its own ingredients', function () {
    $project = KitchenProject::factory()->withIngredients(3)->create();

    expect($project->refresh()->ingredients)->toHaveCount(3);
});

it('keeps the freezer item when the project that made it goes away', function () {
    $soup = FreezerItem::factory()->create();
    $project = KitchenProject::factory()->producing($soup)->create();

    $project->delete();

    // There is still soup in the freezer even if we stop making it.
    expect(FreezerItem::find($soup->id))->not->toBeNull();
});

it('forgets what it produced when the freezer item is deleted', function () {
    $soup = FreezerItem::factory()->create();
    $project = KitchenProject::factory()->producing($soup)->create();

    $soup->delete();

    expect($project->refresh()->produces_freezer_item_id)->toBeNull();
});

it('refuses two projects with the same name', function () {
    KitchenProject::factory()->create(['name' => 'Fresh hummus']);

    expect(fn () => KitchenProject::factory()->create(['name' => 'Fresh hummus']))
        ->toThrow(UniqueConstraintViolationException::class);
});
