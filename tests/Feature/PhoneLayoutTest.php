<?php

use App\Models\BulkIngredient;
use App\Models\FreezerItem;
use App\Models\KidBackupFood;
use App\Models\KitchenProject;
use App\Models\Recipe;

/*
 * Every screen has to work on a phone, one-handed (MM-15).
 *
 * The layouts are mobile-first — the base classes are the phone, and `sm:`
 * widens them for the kitchen tablet and a laptop — so what has to hold on a
 * 390px screen is in the markup rather than behind a media query. These assert
 * it where it can rot silently: a control added later without the shared form
 * partials would still render, just too small to hit while holding a child.
 *
 * Two rules, both from MM-4's partials:
 *
 *   - every button is at least 44px tall (`min-h-11`), which is the smallest
 *     comfortable tap target;
 *   - every field is full width and 16px (`w-full`, `text-base`); anything
 *     smaller makes iOS Safari zoom the page when the field takes focus, and
 *     the household then has to pinch back out to reach the save button.
 */

/**
 * Every control on a page, as its opening tag.
 *
 * @return array<int, string>
 */
function controlsOn(string $html): array
{
    preg_match_all('/<(?:input|select|textarea|button)\b[^>]*>/i', $html, $matches);

    return $matches[0];
}

/**
 * The URL of a screen, filling in the editors' route model.
 */
function theScreenAt(string $name): string
{
    return match ($name) {
        'recipes.edit' => route($name, Recipe::query()->firstOrFail()),
        'kitchen.projects.edit' => route($name, KitchenProject::query()->firstOrFail()),
        default => route($name),
    };
}

beforeEach(function () {
    // Something in every list, so the rows themselves are rendered too.
    $recipe = Recipe::factory()->withIngredients(2)->withPrepStep()->create();
    $recipe->bulkIngredients()->attach(BulkIngredient::factory()->create());

    $soup = FreezerItem::factory()->portions(3)->create();
    KitchenProject::factory()->producing($soup)->withIngredients(2)->create();
    KidBackupFood::factory()->create();
});

it('gives every button a thumb-sized tap target', function (string $path) {
    $html = $this->get(theScreenAt($path))->assertOk()->getContent();

    $buttons = array_filter(controlsOn($html), fn (string $tag) => str_starts_with(strtolower($tag), '<button'));

    expect($buttons)->not->toBeEmpty();

    foreach ($buttons as $button) {
        expect($button)->toContain('min-h-11');
    }
})->with('edit screens');

it('makes every field full width and big enough not to zoom the page', function (string $path) {
    $html = $this->get(theScreenAt($path))->assertOk()->getContent();

    $fields = array_filter(
        controlsOn($html),
        fn (string $tag) => ! str_starts_with(strtolower($tag), '<button')
            // A checkbox is sized on its own (`size-5`) and sits inside a
            // 44px label, which is the tap target.
            && ! str_contains($tag, 'type="checkbox"')
            && ! str_contains($tag, 'type="hidden"'),
    );

    // Guarded, like the buttons: a page that stopped rendering its fields would
    // otherwise pass this test by having nothing to check.
    expect($fields)->not->toBeEmpty();

    foreach ($fields as $field) {
        expect($field)->toContain('w-full')->toContain('text-base');
    }
})->with('screens with fields');

it('keeps a checkbox’s label as the tap target', function () {
    $html = $this->get(theScreenAt('recipes.edit'))->assertOk()->getContent();

    expect($html)->toContain('class="flex min-h-11 items-center gap-3"');
});

it('keeps the save button reachable from anywhere in a long form', function (string $path) {
    // Sticky at the bottom on a phone, so a long recipe never has to be
    // scrolled to the end to be saved.
    $html = $this->get(theScreenAt($path))->assertOk()->getContent();

    expect($html)->toContain('sticky bottom-0');
})->with([
    'recipes.new',
    'recipes.bulk-ingredients',
    'kitchen',
    'kitchen.projects.new',
    'kid-foods',
    'settings',
]);

dataset('edit screens', [
    'the vault' => ['recipes'],
    'a recipe' => ['recipes.edit'],
    'a new recipe' => ['recipes.new'],
    'the bulk packs' => ['recipes.bulk-ingredients'],
    'the kitchen' => ['kitchen'],
    'a project' => ['kitchen.projects.edit'],
    'a new project' => ['kitchen.projects.new'],
    'the kid foods' => ['kid-foods'],
    'the settings' => ['settings'],
]);

// Every screen above except the vault, which is a list of recipes with no
// fields of its own.
dataset('screens with fields', [
    'a recipe' => ['recipes.edit'],
    'a new recipe' => ['recipes.new'],
    'the bulk packs' => ['recipes.bulk-ingredients'],
    'the kitchen' => ['kitchen'],
    'a project' => ['kitchen.projects.edit'],
    'a new project' => ['kitchen.projects.new'],
    'the kid foods' => ['kid-foods'],
    'the settings' => ['settings'],
]);
