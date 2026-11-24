<?php

use App\Grocery\GroceryLine;
use App\Grocery\GroceryList;
use App\Grocery\GrocerySection;
use App\Grocery\GrocerySectionOrder;

/*
 * The order the list's sections come out in.
 *
 * Store sections are free text from the household's own recipes, so the order you
 * meet them in is theirs too — the `store_section_order` setting, not a list of
 * aisles in code (AGENTS.md). With nothing set, alphabetical at least puts the
 * same section in the same place every week.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

it('walks the store the way the household wrote it down', function () {
    $order = new GrocerySectionOrder(['Produce', 'Meat', 'Dairy']);

    expect($order->sort(['Dairy', 'Pantry', 'Meat', 'Produce']))
        ->toBe(['Produce', 'Meat', 'Dairy', 'Pantry']);
});

it('matches a section however it was capitalised', function () {
    $order = new GrocerySectionOrder(['produce', 'MEAT']);

    expect($order->sort(['Meat', 'Dry goods', 'Produce']))
        ->toBe(['Produce', 'Meat', 'Dry goods']);
});

it('falls back to alphabetical when the household has not said', function () {
    $order = new GrocerySectionOrder;

    expect($order->sort(['Produce', 'Dairy', 'meat', 'Bakery']))
        ->toBe(['Bakery', 'Dairy', 'meat', 'Produce']);
});

it('puts the sections nobody named after the ones they did', function () {
    $order = new GrocerySectionOrder(['Meat']);

    expect($order->sort(['Dairy', 'Bakery', 'Meat']))
        ->toBe(['Meat', 'Bakery', 'Dairy']);
});

it('leaves the odds and ends with no section until last', function () {
    $order = new GrocerySectionOrder;

    expect($order->sort([GrocerySection::UNSECTIONED, 'Produce', 'Apples']))
        ->toBe(['Apples', 'Produce', GrocerySection::UNSECTIONED]);
});

it('lets the household put the unsectioned leftovers somewhere else', function () {
    $order = new GrocerySectionOrder([GrocerySection::UNSECTIONED, 'Produce']);

    expect($order->sort(['Produce', GrocerySection::UNSECTIONED, 'Dairy']))
        ->toBe([GrocerySection::UNSECTIONED, 'Produce', 'Dairy']);
});

it('ignores blanks and repeats in the setting', function () {
    $order = new GrocerySectionOrder(['Produce', '', '  ', 'Produce', 'Meat']);

    expect($order->sort(['Meat', 'Produce']))->toBe(['Produce', 'Meat']);
});

it('orders a whole list by it, with the basics still last', function () {
    $list = GroceryList::of([
        new GroceryLine(name: 'Lettuce', quantity: 1, unit: 'head', section: 'Produce'),
        new GroceryLine(name: 'Ground beef', quantity: 2, unit: 'lb', section: 'Meat'),
        new GroceryLine(name: 'Cumin', quantity: 1, unit: 'tbsp', section: 'Pantry', pantryBasic: true),
    ], new GrocerySectionOrder(['Meat', 'Produce']));

    expect($list->sectionNames())->toBe(['Meat', 'Produce', GrocerySection::PANTRY_BASICS]);
});

it('sorts the lines inside a section by name', function () {
    $list = GroceryList::of([
        new GroceryLine(name: 'Onion', section: 'Produce'),
        new GroceryLine(name: 'garlic', section: 'Produce'),
        new GroceryLine(name: 'Lettuce', section: 'Produce'),
    ]);

    expect(array_map(fn (GroceryLine $line) => $line->name, $list->lines()))
        ->toBe(['garlic', 'Lettuce', 'Onion']);
});

it('reads one aisle as one section however it was typed', function () {
    // Store sections are free text a person types (MM-3), so this is the one
    // input guaranteed to vary. Two headings two lines apart is how the lettuce
    // gets crossed off and the onion never seen.
    $list = GroceryList::of([
        new GroceryLine(name: 'Lettuce', quantity: 1, unit: 'head', section: 'Produce'),
        new GroceryLine(name: 'Onion', quantity: 2, section: 'produce'),
        new GroceryLine(name: 'Garlic', quantity: 3, unit: 'clove', section: '  PRODUCE '),
    ]);

    expect($list->sectionNames())->toBe(['Produce'])
        // The first spelling is the one the household reads, as for a line's name.
        ->and($list->sections[0]->count())->toBe(3);
});

it('counts a section once when the household typed it twice', function () {
    $order = new GrocerySectionOrder(['Meat']);

    expect($order->sort(['Produce', 'produce', 'Meat']))->toBe(['Meat', 'Produce']);
});
