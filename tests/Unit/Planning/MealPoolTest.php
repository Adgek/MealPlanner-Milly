<?php

use App\Planning\MealPool;

/*
 * The window's ledger: what has been used, and how many freezer portions are
 * still free. Small, and worth pinning down on its own, because a portion counted
 * twice means MM-12 warns about a stash that is actually fine — or doesn't warn
 * about one that isn't.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

it('starts with nothing used and every portion free', function () {
    $soup = aFreezerMeal(1, 3);
    $pool = new MealPool([aRecipe(1)], [$soup]);

    expect($pool->isUsed('recipe:1'))->toBeFalse()
        ->and($pool->portionsAvailable($soup))->toBe(3);
});

it('spends a portion when a freezer meal is taken, and hands it back on release', function () {
    $soup = aFreezerMeal(1, 3);
    $pool = new MealPool([], [$soup]);

    $pool->take($soup);

    expect($pool->portionsAvailable($soup))->toBe(2)
        ->and($pool->isUsed('freezer:1'))->toBeTrue();

    $pool->release($soup);

    expect($pool->portionsAvailable($soup))->toBe(3)
        ->and($pool->isUsed('freezer:1'))->toBeFalse();
});

it('uses a recipe up without any portion arithmetic', function () {
    $bulgogi = aRecipe(1);
    $pool = new MealPool([$bulgogi]);

    $pool->take($bulgogi);

    expect($pool->isUsed('recipe:1'))->toBeTrue();

    $pool->release($bulgogi);

    expect($pool->isUsed('recipe:1'))->toBeFalse();
});

it('counts a locked night as used without spending a portion again', function () {
    // The locked night's portion left the stash when it was planned, so the pool
    // must not take a second one for the same meal.
    $soup = aFreezerMeal(1, 1);
    $pool = new MealPool([], [$soup]);

    $pool->markUsed('freezer:1');

    expect($pool->isUsed('freezer:1'))->toBeTrue()
        ->and($pool->portionsAvailable($soup))->toBe(1);
});

it('never lets a release push the count above what is in the freezer', function () {
    $soup = aFreezerMeal(1, 2);
    $pool = new MealPool([], [$soup]);

    $pool->release($soup);

    expect($pool->portionsAvailable($soup))->toBe(2);
});

it('holds the meals in id order, so a seed means the same thing twice', function () {
    $pool = new MealPool(
        [aRecipe(3), aRecipe(1), aRecipe(2)],
        [aFreezerMeal(9), aFreezerMeal(4)],
    );

    expect(array_map(fn ($option) => $option->key(), $pool->recipes()))
        ->toBe(['recipe:1', 'recipe:2', 'recipe:3'])
        ->and(array_map(fn ($option) => $option->key(), $pool->freezerMeals()))
        ->toBe(['freezer:4', 'freezer:9']);
});

it('finds a meal by the key the plan stored', function () {
    $pool = new MealPool([aRecipe(1, name: 'Bulgogi')], [aFreezerMeal(2, 1, name: 'Frozen minestrone')]);

    expect($pool->option('recipe:1')?->name())->toBe('Bulgogi')
        ->and($pool->option('freezer:2')?->name())->toBe('Frozen minestrone')
        ->and($pool->option('recipe:99'))->toBeNull();
});
