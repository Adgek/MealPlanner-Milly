<?php

use App\Grocery\GroceryList;
use App\Grocery\GroceryMerge;
use App\Grocery\GrocerySection;

/*
 * The merge: "1 lb + 1 lb ground beef = 2 lb", and every awkward case either side
 * of it. This is the part of the grocery list that will be wrong in a way nobody
 * notices until they are standing in the shop, so each rule is pinned down on its
 * own, with no framework and no database (AGENTS.md).
 *
 * The rule itself is deliberately narrow: same name, same unit, amounts added,
 * never a conversion (docs/decisions.md, MM-7).
 */

function theMerged(array $needs): GroceryList
{
    return (new GroceryMerge)->merge($needs);
}

it('adds up two needs for the same thing in the same unit', function () {
    $list = theMerged([
        aNeed('Ground beef', 1, 'lb', 'Meat', meal: 'Beef Bulgogi'),
        aNeed('Ground beef', 1, 'lb', 'Meat', meal: 'White Boy Tacos'),
    ]);

    expect($list->count())->toBe(1)
        ->and($list->lineFor('Ground beef', 'lb')->amount())->toBe('2 lb');
});

it('keeps two lines when the units differ, because nothing here converts', function () {
    // A pound is not 500 g, and guessing at the till is worse than two lines on a
    // list somebody reads anyway.
    $list = theMerged([
        aNeed('Ground beef', 1, 'lb', 'Meat', meal: 'Chili'),
        aNeed('Ground beef', 500, 'g', 'Meat', meal: 'Beef Bulgogi'),
    ]);

    expect($list->count())->toBe(2)
        ->and($list->lineFor('Ground beef', 'lb')->amount())->toBe('1 lb')
        ->and($list->lineFor('Ground beef', 'g')->amount())->toBe('500 g');
});

it('treats one spelling with another’s capitals and spacing as the same thing', function () {
    $list = theMerged([
        aNeed('Ground beef', 1, 'lb', 'Meat'),
        aNeed('ground  BEEF', 2, 'LB', 'Meat'),
    ]);

    expect($list->count())->toBe(1)
        // The first spelling wins: the household reads the earliest night's.
        ->and($list->lines()[0]->name)->toBe('Ground beef')
        ->and($list->lines()[0]->amount())->toBe('3 lb');
});

it('shows which meals use each item, once each and in night order', function () {
    $list = theMerged([
        aNeed('Garlic', 3, 'clove', 'Produce', meal: 'Beef Bulgogi'),
        aNeed('Garlic', 2, 'clove', 'Produce', meal: 'Quick Pasta'),
        aNeed('Garlic', 1, 'clove', 'Produce', meal: 'Beef Bulgogi'),
    ]);

    expect($list->lineFor('Garlic', 'clove')->meals)->toBe(['Beef Bulgogi', 'Quick Pasta'])
        ->and($list->lineFor('Garlic', 'clove')->mealsLabel())->toBe('Beef Bulgogi, Quick Pasta')
        ->and($list->lineFor('Garlic', 'clove')->amount())->toBe('6 clove');
});

it('leaves a thing nobody measured with no amount at all', function () {
    // "A splash of soy": the amount and the unit are both optional on an
    // ingredient, and inventing a number would be worse than printing none.
    $list = theMerged([
        aNeed('Soy sauce', section: 'Pantry', meal: 'Beef Bulgogi'),
        aNeed('Soy sauce', section: 'Pantry', meal: 'Pupusas'),
    ]);

    $line = $list->lineFor('Soy sauce');

    expect($list->count())->toBe(1)
        ->and($line->amount())->toBeNull()
        ->and($line->describe())->toBe('Soy sauce')
        ->and($line->meals)->toBe(['Beef Bulgogi', 'Pupusas']);
});

it('counts what was measured and still lists the meal that did not say', function () {
    $list = theMerged([
        aNeed('Lemon', 1, section: 'Produce', meal: 'Fresh hummus'),
        aNeed('Lemon', null, section: 'Produce', meal: 'Quick Pasta'),
    ]);

    expect($list->lineFor('Lemon')->amount())->toBe('1')
        ->and($list->lineFor('Lemon')->meals)->toBe(['Fresh hummus', 'Quick Pasta']);
});

it('writes an amount the way a person does', function () {
    $list = theMerged([
        aNeed('Beef', 0.5, 'lb', 'Meat'),
        aNeed('Beef', 1, 'lb', 'Meat'),
        aNeed('Cumin', 1, 'tbsp', 'Pantry'),
    ]);

    expect($list->lineFor('Beef', 'lb')->amount())->toBe('1.5 lb')
        ->and($list->lineFor('Cumin', 'tbsp')->amount())->toBe('1 tbsp');
});

it('puts the pantry basics in a short section at the end', function () {
    $list = theMerged([
        aNeed('Olive oil', 2, 'tbsp', 'Pantry', pantryBasic: true, meal: 'Quick Pasta'),
        aNeed('Lettuce', 1, 'head', 'Produce', meal: 'White Boy Tacos'),
    ]);

    expect($list->sectionNames())->toBe(['Produce', GrocerySection::PANTRY_BASICS])
        ->and($list->pantryBasics->count())->toBe(1)
        ->and($list->pantryBasics->lines[0]->name)->toBe('Olive oil')
        // Last, so the household checks the fridge for it after the shopping.
        ->and($list->lines()[$list->count() - 1]->name)->toBe('Olive oil');
});

it('only treats a line as a basic when every meal says it is one', function () {
    // One recipe calls for 500 g of rice and means it. Relegating that to "check
    // you have" is how dinner ends up without rice.
    $list = theMerged([
        aNeed('Rice', 2, 'cup', 'Dry goods', pantryBasic: true, meal: 'Beef Bulgogi'),
        aNeed('Rice', 3, 'cup', 'Dry goods', pantryBasic: false, meal: 'Chili'),
    ]);

    expect($list->pantryBasics)->toBeNull()
        ->and($list->lineFor('Rice', 'cup')->pantryBasic)->toBeFalse()
        ->and($list->lineFor('Rice', 'cup')->amount())->toBe('5 cup');
});

it('merges two recipes that disagree about the aisle, keeping the first one', function () {
    // Merging the amount matters more than the aisle, and the household can fix
    // the recipe. What must not happen is two ground beef lines.
    $list = theMerged([
        aNeed('Ground beef', 1, 'lb', 'Meat'),
        aNeed('Ground beef', 1, 'lb', 'Butcher'),
    ]);

    expect($list->count())->toBe(1)
        ->and($list->lines()[0]->section)->toBe('Meat')
        ->and($list->lines()[0]->amount())->toBe('2 lb');
});

it('gives a thing with no section a home at the end of the shopping', function () {
    $list = theMerged([
        aNeed('Birthday candles', 1, 'box'),
        aNeed('Lettuce', 1, 'head', 'Produce'),
    ]);

    expect($list->sectionNames())->toBe(['Produce', GrocerySection::UNSECTIONED]);
});

it('hands a page the whole list as plain arrays', function () {
    // The structured output the ticket asks for: what MM-8 renders and MM-14
    // shows on the tablet, with no models behind it.
    $list = theMerged([
        aNeed('Ground beef', 1, 'lb', 'Meat', meal: 'Chili'),
        aNeed('Ground beef', 1, 'lb', 'Meat', meal: 'White Boy Tacos'),
        aNeed('Cumin', 1, 'tbsp', 'Pantry', pantryBasic: true, meal: 'Chili'),
    ]);

    expect($list->toArray())->toBe([
        'sections' => [[
            'section' => 'Meat',
            'lines' => [[
                // Null until the line is stored; MM-8 ticks a line by its id.
                'id' => null,
                'name' => 'Ground beef',
                'quantity' => 2.0,
                'unit' => 'lb',
                'amount' => '2 lb',
                'section' => 'Meat',
                'pantry_basic' => false,
                'meals' => ['Chili', 'White Boy Tacos'],
                'checked' => false,
                'checked_at' => null,
            ]],
        ]],
        'pantry_basics' => [
            'section' => GrocerySection::PANTRY_BASICS,
            'lines' => [[
                'id' => null,
                'name' => 'Cumin',
                'quantity' => 1.0,
                'unit' => 'tbsp',
                'amount' => '1 tbsp',
                'section' => 'Pantry',
                'pantry_basic' => true,
                'meals' => ['Chili'],
                'checked' => false,
                'checked_at' => null,
            ]],
        ],
        'count' => 2,
        'checked' => 0,
    ]);
});

it('keeps an empty window’s list empty', function () {
    expect(theMerged([])->isEmpty())->toBeTrue()
        ->and(theMerged([])->sectionNames())->toBe([]);
});

it('adds an amount somebody typed as zero without losing the line', function () {
    // Zero is an amount, not a missing one: the line still has to be bought.
    $list = theMerged([
        aNeed('Soy sauce', 0, 'ml', 'Pantry', meal: 'Beef Bulgogi'),
        aNeed('Soy sauce', 0, 'ml', 'Pantry', meal: 'Pupusas'),
    ]);

    expect($list->count())->toBe(1)
        ->and($list->lineFor('Soy sauce', 'ml')->amount())->toBe('0 ml');
});

it('adds a negative amount as written rather than quietly tidying it', function () {
    // Nonsense in a recipe shows up on the list, where somebody sees it and fixes
    // the recipe. Clamping it would hide the mistake and change the arithmetic.
    $list = theMerged([
        aNeed('Cream', -1, 'cup', 'Dairy', meal: 'Chili'),
        aNeed('Cream', 2, 'cup', 'Dairy', meal: 'Quick Pasta'),
    ]);

    expect($list->lineFor('Cream', 'cup')->amount())->toBe('1 cup')
        ->and(theMerged([aNeed('Cream', -1, 'cup')])->lineFor('Cream', 'cup')->amount())->toBe('-1 cup');
});

it('writes a large amount plainly, with no thousands separator', function () {
    $list = theMerged([
        aNeed('Rice', 500000, 'g', 'Dry goods'),
        aNeed('Rice', 499999.99, 'g', 'Dry goods'),
    ]);

    expect($list->lineFor('Rice', 'g')->amount())->toBe('999999.99 g');
});

it('adds fractions up to what a person wrote, not what binary floats say', function () {
    // 0.1 + 0.1 + 0.1 is 0.30000000000000004 in floating point. The list says
    // "0.3", and the stored row round-trips as the same amount, which is what the
    // "stamped only when a line really moved" promise rests on.
    $list = theMerged([
        aNeed('Vanilla', 0.1, 'tsp', 'Pantry'),
        aNeed('Vanilla', 0.1, 'tsp', 'Pantry'),
        aNeed('Vanilla', 0.1, 'tsp', 'Pantry'),
    ]);

    expect($list->lineFor('Vanilla', 'tsp')->amount())->toBe('0.3 tsp')
        ->and($list->lineFor('Vanilla', 'tsp')->toRow()['quantity'])->toBe(0.3);
});
