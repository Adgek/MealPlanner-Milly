<?php

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use App\Grocery\GroceryListBuilder;
use App\Grocery\MergeKey;
use App\Models\Plan;
use App\Models\PlanGroceryItem;
use App\Models\Recipe;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * One thing on the grocery list is one row, and one checkbox (MM-23).
 *
 * `plan_grocery_items` is unique on (plan_id, merge_key), where the key is the
 * line's own identity — the merge key MM-7 folds two needs on, hashed — so the
 * database refuses a second row for something the window already has. That
 * matters before MM-8 renders checkboxes: two rows are two checkboxes for one
 * thing, and ticking one leaves the other unticked.
 *
 * Time is frozen at Sunday 11 January 2026, a shopping day, so the next window is
 * Monday the 12th to Wednesday the 14th throughout.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-01-11 08:00:00', 'America/Toronto'));
    theWeekIs();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A dinner that needs one thing, which is all any of these need.
 */
function aDinnerThatNeeds(string $name, string $ingredient, ?float $quantity = 1, ?string $unit = 'lb'): Recipe
{
    $recipe = Recipe::factory()->create([
        'name' => $name,
        'cookable_by' => CookableBy::Both,
        'effort_tier' => EffortTier::Workhorse30,
    ]);

    $recipe->ingredients()->create([
        'name' => $ingredient,
        'quantity' => $quantity,
        'unit' => $unit,
        'store_section' => 'Meat',
    ]);

    return $recipe;
}

/**
 * A second row for a line, written the only way one can still exist: straight
 * into the table, under a key that is not its own.
 *
 * Everything that goes through the app derives the key from the name and the
 * unit (PlanGroceryItem::booted()), so the index catches it. A row whose stored
 * key disagrees with its own name is what the table held before this migration,
 * or what hand-written SQL could still put there — and it is what the rebuild's
 * backstop is for.
 *
 * @param  array<string, mixed>  $attributes
 */
function aRowTheIndexCannotSee(Plan $plan, array $attributes = []): PlanGroceryItem
{
    $id = DB::table('plan_grocery_items')->insertGetId([
        'plan_id' => $plan->getKey(),
        'name' => 'Ground beef',
        'meals' => json_encode(['Chili']),
        'quantity' => 3,
        'unit' => 'lb',
        'store_section' => 'Meat',
        'merge_key' => MergeKey::stored('a key from before the index existed'),
        'pantry_basic' => false,
        'checked' => false,
        'checked_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);

    return PlanGroceryItem::query()->findOrFail($id);
}

/**
 * The MM-23 migration itself, so the part of it worth a test can be run against
 * rows that are already there: which of two rows for one line survives.
 */
function theMergeKeyMigration(): object
{
    return require database_path('migrations/2026_11_25_000001_add_merge_key_to_plan_grocery_items_table.php');
}

it('holds the window to one row per line in the schema itself', function () {
    // What `show index from plan_grocery_items` had to say before this was only
    // PRIMARY and the non-unique (plan_id, store_section). The index below is
    // what makes a duplicate impossible rather than repaired afterwards, and the
    // column is ascii_bin so the database compares a key the way MergeKey does:
    // under the table's own utf8mb4_unicode_ci, 'cafe' = 'café' is true.
    $indexes = collect(DB::select('show index from plan_grocery_items'))
        ->where('Key_name', 'plan_grocery_items_plan_id_merge_key_unique')
        ->sortBy('Seq_in_index');

    $column = collect(DB::select('show full columns from plan_grocery_items'))
        ->firstWhere('Field', 'merge_key');

    expect($indexes->pluck('Column_name')->all())->toBe(['plan_id', 'merge_key'])
        ->and($indexes->pluck('Non_unique')->unique()->all())->toBe([0])
        ->and($column->Collation)->toBe('ascii_bin')
        ->and($column->Null)->toBe('NO');
});

it('refuses a second row for a line the window already has', function () {
    $plan = aPlannedWindowOf(aDinnerThatNeeds('Chili', 'Ground beef'));

    expect(fn () => PlanGroceryItem::factory()->for($plan)->create([
        'name' => 'Ground beef',
        'quantity' => 1,
        'unit' => 'lb',
    ]))->toThrow(UniqueConstraintViolationException::class)
        ->and(PlanGroceryItem::query()->where('plan_id', $plan->getKey())->count())->toBe(1);
});

it('refuses it however the second one was spelled', function () {
    // The stored key is the merge identity, not the text: "Ground beef",
    // "ground  BEEF" and " Ground Beef " are one thing to buy (MM-7), so they are
    // one row — which is the point of storing the key rather than the columns.
    $plan = aPlannedWindowOf(aDinnerThatNeeds('Chili', 'Ground beef'));

    expect(fn () => PlanGroceryItem::factory()->for($plan)->create([
        'name' => 'ground  BEEF',
        'unit' => ' LB ',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('still stores a need with no unit, and refuses the second of those too', function () {
    // The reason the key is a column. `unit` is nullable and MySQL counts nulls
    // as distinct in a unique index, so the obvious index on
    // (plan_id, name, unit) would have let two of these through — and a need with
    // no unit is ordinary: "a splash of soy", a kid food sold in packs.
    $plan = aPlannedWindowOf(aDinnerThatNeeds('Stir fry', 'Soy sauce', null, null));

    $row = PlanGroceryItem::query()->where('plan_id', $plan->getKey())->firstWhere('name', 'Soy sauce');

    expect($row)->not->toBeNull()
        ->and($row->unit)->toBeNull()
        ->and($row->quantity)->toBeNull()
        ->and($row->merge_key)->toBe(MergeKey::stored(MergeKey::of('Soy sauce', null)))
        ->and(fn () => PlanGroceryItem::factory()->for($plan)->create([
            'name' => 'Soy sauce',
            'quantity' => null,
            'unit' => null,
        ]))->toThrow(UniqueConstraintViolationException::class)
        // An empty unit is the same thing as none, so it is the same row.
        ->and(fn () => PlanGroceryItem::factory()->for($plan)->create([
            'name' => 'Soy sauce',
            'unit' => '',
        ]))->toThrow(UniqueConstraintViolationException::class);
});

it('lets two windows ask for the same thing', function () {
    // The key is per plan: every window buys its own beef.
    $plan = aPlannedWindowOf(aDinnerThatNeeds('Chili', 'Ground beef'));
    $other = Plan::factory()->window('2026-01-14')->create();

    $row = PlanGroceryItem::factory()->for($other)->create(['name' => 'Ground beef', 'unit' => 'lb']);

    expect($row->merge_key)->toBe(PlanGroceryItem::query()->where('plan_id', $plan->getKey())->first()->merge_key)
        ->and(PlanGroceryItem::count())->toBe(2);
});

it('keeps one line when a line has somehow been stored twice', function () {
    // The backstop behind the index, for a row from before it or one written by
    // hand. The ticked row survives, that tick being somebody's work at the
    // fridge, and the tidy-up is not treated as the list changing.
    $chili = aDinnerThatNeeds('Chili', 'Ground beef');
    $plan = aPlannedWindowOf($chili, $chili, $chili);

    $stamped = $plan->refresh()->grocery_list_changed_at;
    $written = PlanGroceryItem::query()->where('plan_id', $plan->getKey())->sole();
    $ticked = aRowTheIndexCannotSee($plan, ['checked' => true, 'checked_at' => now()]);

    $update = (new GroceryListBuilder)->rebuild($plan->refresh());

    $rows = PlanGroceryItem::query()->where('plan_id', $plan->getKey())->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->getKey())->toBe($ticked->getKey())
        ->and($rows->first()->checked)->toBeTrue()
        // And its key is its own again, so the index can hold it from now on.
        ->and($rows->first()->merge_key)->toBe(MergeKey::stored(MergeKey::of('Ground beef', 'lb')))
        ->and($update->changedAnything())->toBeFalse()
        ->and($plan->refresh()->grocery_list_changed_at->eq($stamped))->toBeTrue()
        // The row that went is the one the rebuild itself had written.
        ->and(PlanGroceryItem::query()->whereKey($written->getKey())->exists())->toBeFalse();
});

it('keeps the ticked row when the migration finds a line stored twice', function () {
    // The household's own database may already hold duplicates, so the migration
    // has to survive real data: it collapses what it finds before the index goes
    // on, and it keeps the ticked row rather than the one it happened to read
    // first. These three rows are what a table from before MM-23 looks like —
    // one line twice, plus a need with no unit, under keys that mean nothing.
    $plan = Plan::factory()->window('2026-01-11')->create();

    $oldest = aRowTheIndexCannotSee($plan, ['merge_key' => 'a stale key', 'quantity' => 1]);
    $ticked = aRowTheIndexCannotSee($plan, [
        'merge_key' => 'another stale key',
        'name' => 'ground  beef',
        'checked' => true,
        'checked_at' => now(),
    ]);
    $soy = aRowTheIndexCannotSee($plan, [
        'merge_key' => 'a third stale key',
        'name' => 'Soy sauce',
        'quantity' => null,
        'unit' => null,
    ]);

    theMergeKeyMigration()->storeTheKeys();

    $rows = PlanGroceryItem::query()->where('plan_id', $plan->getKey())->get();

    expect($rows->pluck('id')->all())->toEqualCanonicalizing([$ticked->getKey(), $soy->getKey()])
        ->and($ticked->refresh()->checked)->toBeTrue()
        // The two spellings were one line all along, and the key says so now.
        ->and($ticked->merge_key)->toBe(MergeKey::stored(MergeKey::of('Ground beef', 'lb')))
        // A null unit gets a real key, not a null one: that was the loophole.
        ->and($soy->refresh()->merge_key)->toBe(MergeKey::stored(MergeKey::of('Soy sauce', null)))
        ->and(PlanGroceryItem::query()->whereKey($oldest->getKey())->exists())->toBeFalse();
});

it('is not put off by a window that has no duplicates at all', function () {
    // The migration runs on every database, most of which have nothing to repair,
    // and running it twice must do nothing the second time.
    $plan = aPlannedWindowOf(aDinnerThatNeeds('Chili', 'Ground beef'));
    $before = PlanGroceryItem::query()->where('plan_id', $plan->getKey())->get();

    theMergeKeyMigration()->storeTheKeys();
    theMergeKeyMigration()->storeTheKeys();

    $after = PlanGroceryItem::query()->where('plan_id', $plan->getKey())->get();

    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->pluck('merge_key')->all())->toBe($before->pluck('merge_key')->all());
});
