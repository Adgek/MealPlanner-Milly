<?php

use App\Enums\CookAssignment;
use App\Grocery\GroceryBasket;
use App\Grocery\GroceryMerge;
use App\Grocery\IngredientNeed;
use App\Planning\Pick;

/*
 * Which of a window's nights the shop actually has to cover.
 *
 * A freezer night and a night off add no ingredients, and a night nothing fitted
 * must not quietly add any either — all three are real states MM-6 stores, and
 * getting this wrong means buying for a dinner that isn't happening or not buying
 * for one that is.
 *
 * Unit tests: no framework, no database (AGENTS.md). The picks are plain values
 * and the ingredients are handed in as needs keyed by recipe id, which is exactly
 * what GroceryListBuilder loads from the database.
 */

/**
 * The ingredients of the three example recipes, keyed by recipe id.
 *
 * @return array<int, array<int, IngredientNeed>>
 */
function theVaultsNeeds(): array
{
    return [
        1 => [
            aNeed('Ground beef', 1, 'lb', 'Meat'),
            aNeed('Garlic', 3, 'clove', 'Produce'),
        ],
        2 => [
            aNeed('Ground beef', 1, 'lb', 'Meat'),
            aNeed('Tortillas', 8, 'count', 'Dry goods'),
        ],
        3 => [
            aNeed('Pasta', 500, 'g', 'Dry goods'),
        ],
    ];
}

function theBasketFor(array $picks, array $alwaysNeeded = []): array
{
    return GroceryBasket::needs($picks, theVaultsNeeds(), $alwaysNeeded);
}

it('combines the ingredients of every recipe the window planned', function () {
    $list = (new GroceryMerge)->merge(theBasketFor([
        Pick::recipe(aNight('2026-01-12'), aRecipe(1, name: 'Beef Bulgogi')),
        Pick::recipe(aNight('2026-01-13'), aRecipe(2, name: 'White Boy Tacos')),
    ]));

    expect($list->lineFor('Ground beef', 'lb')->amount())->toBe('2 lb')
        ->and($list->lineFor('Ground beef', 'lb')->meals)->toBe(['Beef Bulgogi', 'White Boy Tacos'])
        ->and($list->lineFor('Tortillas', 'count')->amount())->toBe('8 count');
});

it('buys nothing for a freezer night', function () {
    // The food was cooked and frozen weeks ago; the portion it holds is the record
    // of that. A freezer night is a meal that needs no shopping.
    $needs = theBasketFor([
        Pick::recipe(aNight('2026-01-12'), aRecipe(1, name: 'Beef Bulgogi')),
        Pick::freezerMeal(aNight('2026-01-13'), aFreezerMeal(9, name: 'Frozen minestrone')),
    ]);

    $list = (new GroceryMerge)->merge($needs);

    expect($list->lineFor('Ground beef', 'lb')->amount())->toBe('1 lb')
        ->and($list->lineFor('Ground beef', 'lb')->meals)->toBe(['Beef Bulgogi'])
        ->and($list->count())->toBe(2);
});

it('buys nothing for a night nobody is cooking', function () {
    $needs = theBasketFor([
        Pick::nightOff(aNight('2026-01-12', CookAssignment::Nobody), 'Eating out'),
        Pick::recipe(aNight('2026-01-13'), aRecipe(3, name: 'Quick Pasta')),
    ]);

    expect((new GroceryMerge)->merge($needs)->count())->toBe(1);
});

it('buys nothing for a night nothing fitted', function () {
    // "Nothing fits" is a stored outcome, not an empty one (MM-6). The household
    // is being asked to add a recipe, not to shop for a dinner that doesn't exist.
    $needs = theBasketFor([
        Pick::nothingFits(aNight('2026-01-12'), aNothingFitsReason()),
        Pick::unplanned(aNight('2026-01-13')),
    ]);

    expect($needs)->toBe([])
        ->and((new GroceryMerge)->merge($needs)->isEmpty())->toBeTrue();
});

it('always adds the kid backup foods, whatever is for dinner', function () {
    $kidFoods = [
        new IngredientNeed(
            name: 'Chicken nuggets',
            quantity: 1,
            unit: 'bag',
            storeSection: IngredientNeed::KID_BACKUP,
            meal: IngredientNeed::KID_BACKUP,
        ),
    ];

    $list = (new GroceryMerge)->merge(theBasketFor([
        Pick::nightOff(aNight('2026-01-12', CookAssignment::Nobody)),
    ], $kidFoods));

    expect($list->count())->toBe(1)
        ->and($list->sectionNames())->toBe([IngredientNeed::KID_BACKUP])
        ->and($list->lineFor('Chicken nuggets', 'bag')->amount())->toBe('1 bag');
});

it('merges a kid backup food into the recipe line that also wants it', function () {
    // The kid foods go on last on purpose, so a recipe's store section and
    // spelling win and the line reads as one thing to buy.
    $kidFoods = [
        new IngredientNeed(
            name: 'tortillas',
            quantity: 8,
            unit: 'count',
            storeSection: IngredientNeed::KID_BACKUP,
            meal: IngredientNeed::KID_BACKUP,
        ),
    ];

    $list = (new GroceryMerge)->merge(theBasketFor([
        Pick::recipe(aNight('2026-01-12'), aRecipe(2, name: 'White Boy Tacos')),
    ], $kidFoods));

    $line = $list->lineFor('Tortillas', 'count');

    expect($line->amount())->toBe('16 count')
        ->and($line->section)->toBe('Dry goods')
        ->and($line->meals)->toBe(['White Boy Tacos', IngredientNeed::KID_BACKUP]);
});

it('makes a different list once a night has been re-rolled', function () {
    // "Make the list again whenever the plan changes": Tuesday was tacos, the
    // household re-rolled it, and the tortillas are no longer anybody's dinner.
    $before = (new GroceryMerge)->merge(theBasketFor([
        Pick::recipe(aNight('2026-01-12'), aRecipe(1, name: 'Beef Bulgogi')),
        Pick::recipe(aNight('2026-01-13'), aRecipe(2, name: 'White Boy Tacos')),
    ]));

    $after = (new GroceryMerge)->merge(theBasketFor([
        Pick::recipe(aNight('2026-01-12'), aRecipe(1, name: 'Beef Bulgogi')),
        Pick::recipe(aNight('2026-01-13'), aRecipe(3, name: 'Quick Pasta')),
    ]));

    expect($before->lineFor('Tortillas', 'count'))->not->toBeNull()
        ->and($after->lineFor('Tortillas', 'count'))->toBeNull()
        ->and($after->lineFor('Pasta', 'g')->amount())->toBe('500 g')
        // Half of Tuesday's beef left with the tacos.
        ->and($before->lineFor('Ground beef', 'lb')->amount())->toBe('2 lb')
        ->and($after->lineFor('Ground beef', 'lb')->amount())->toBe('1 lb');
});

it('names the recipes whose ingredients the shop needs', function () {
    $ids = GroceryBasket::recipeIds([
        Pick::recipe(aNight('2026-01-12'), aRecipe(1)),
        Pick::freezerMeal(aNight('2026-01-13'), aFreezerMeal(9)),
        Pick::recipe(aNight('2026-01-14'), aRecipe(1)),
        Pick::nightOff(aNight('2026-01-15', CookAssignment::Nobody)),
    ]);

    expect($ids)->toBe([1]);
});
