<?php

use App\Livewire\BulkIngredients;
use App\Livewire\KidFoods;
use App\Livewire\Kitchen;
use App\Livewire\Plan;
use App\Livewire\ProjectEditor;
use App\Livewire\RecipeEditor;
use App\Livewire\Recipes;
use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| There is no authentication: MM-1 chose no login because the app is only
| reachable on the home network. Do not expose these routes to the internet.
|
| Livewire components are routed directly, which renders them as full pages
| inside resources/views/layouts/app.blade.php.
|
| Pages that appear in the navigation are listed in
| resources/views/components/navigation.blade.php. The editor routes below are
| reached from their list page rather than the nav, so the nav stays short
| enough to read on a phone.
|
*/

Route::redirect('/', '/plan')->name('home');

Route::get('/plan', Plan::class)->name('plan');

/*
 * The vault, the stash and the lists the household edits (MM-15). Everything
 * the import in MM-5 can load can also be changed here.
 */
Route::get('/recipes', Recipes::class)->name('recipes');
Route::get('/recipes/new', RecipeEditor::class)->name('recipes.new');
Route::get('/recipes/bulk-ingredients', BulkIngredients::class)->name('recipes.bulk-ingredients');
Route::get('/recipes/{recipe}/edit', RecipeEditor::class)->name('recipes.edit');

Route::get('/kitchen', Kitchen::class)->name('kitchen');
Route::get('/kitchen/projects/new', ProjectEditor::class)->name('kitchen.projects.new');
Route::get('/kitchen/projects/{project}/edit', ProjectEditor::class)->name('kitchen.projects.edit');

Route::get('/kid-foods', KidFoods::class)->name('kid-foods');

Route::get('/settings', Settings::class)->name('settings');
