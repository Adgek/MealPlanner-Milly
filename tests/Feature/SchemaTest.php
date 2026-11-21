<?php

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Storage is MySQL 8.4 with utf8mb4 (MM-1, MM-3). utf8mb4 matters for the obvious
 * reason — a recipe name or a kid note can contain an emoji or an accent, and
 * MySQL's old "utf8" would truncate the row at it.
 *
 * These assertions are about the schema rather than any one model, so they sit
 * here instead of in the per-entity tests.
 */

it('has a table for every entity the app tracks', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue("missing table [{$table}]");
})->with([
    'settings',
    'cook_schedule_nights',
    'recipes',
    'bulk_ingredients',
    'bulk_ingredient_recipe',
    'recipe_prep_steps',
    'ingredients',
    'kitchen_projects',
    'freezer_items',
    'kid_backup_foods',
    'plans',
    'plan_nights',
    'plan_grocery_items',
    'meal_history',
]);

it('stores every table as utf8mb4', function () {
    $database = DB::connection()->getDatabaseName();

    $wrong = collect(DB::select(
        'select table_name as `table`, table_collation as `collation`
         from information_schema.tables
         where table_schema = ? and table_collation not like ?',
        [$database, 'utf8mb4%'],
    ));

    expect($wrong->pluck('table')->all())->toBe([]);
});

it('keeps text that needs four-byte characters intact', function () {
    // The point of utf8mb4 rather than MySQL's three-byte "utf8".
    $name = 'Crème brûlée 🍮';

    $recipe = Recipe::factory()->create(['name' => $name]);

    expect($recipe->refresh()->name)->toBe($name);
});

it('runs on MySQL 8.4', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::scalar('select version()'))->toStartWith('8.4');
});

it('names the test database so a test run cannot touch household data', function () {
    // tests/TestCase.php refuses to boot otherwise; this states the invariant.
    expect(DB::connection()->getDatabaseName())->toEndWith('_test');
});
