<?php

use App\Enums\CookAssignment;
use App\Grocery\GroceryLine;
use App\Grocery\GroceryList;
use App\Grocery\GroceryListText;
use App\Planning\Pick;
use App\Planning\ShoppingWindow;
use Carbon\CarbonImmutable;

/*
 * The list as plain text: what MM-9 posts in the household chat and what
 * `milly:groceries --text` prints.
 *
 * Checkboxes, because the list is read standing at the fridge crossing things
 * off, and no markup, because a chat message and a terminal both have to carry it.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

function aStoredLine(
    string $name,
    ?float $quantity = null,
    ?string $unit = null,
    string $section = 'Produce',
    bool $pantryBasic = false,
    array $meals = [],
    bool $checked = false,
): GroceryLine {
    return new GroceryLine(
        name: $name,
        quantity: $quantity,
        unit: $unit,
        section: $section,
        pantryBasic: $pantryBasic,
        meals: $meals,
        id: 1,
        checked: $checked,
        checkedAt: $checked ? CarbonImmutable::parse('2026-01-11 09:00') : null,
    );
}

function theWindowMonToWed(): ShoppingWindow
{
    return new ShoppingWindow(
        CarbonImmutable::parse('2026-01-11'),
        CarbonImmutable::parse('2026-01-12'),
        CarbonImmutable::parse('2026-01-14'),
    );
}

it('prints a line with its amount and the meals that want it', function () {
    $text = GroceryListText::render(GroceryList::of([
        aStoredLine('Ground beef', 2, 'lb', 'Meat', meals: ['Beef Bulgogi', 'Chili']),
    ]));

    expect($text)->toBe("Meat\n[ ] Ground beef — 2 lb (Beef Bulgogi, Chili)");
});

it('shows a line somebody has already crossed off as crossed off', function () {
    $text = GroceryListText::render(GroceryList::of([
        aStoredLine('Lettuce', 1, 'head', 'Produce', checked: true),
    ]));

    expect($text)->toContain('[x] Lettuce — 1 head');
});

it('keeps the pantry basics in their own short section at the end', function () {
    $text = GroceryListText::render(GroceryList::of([
        aStoredLine('Olive oil', 2, 'tbsp', 'Pantry', pantryBasic: true),
        aStoredLine('Lettuce', 1, 'head', 'Produce'),
    ]));

    expect($text)->toBe(
        "Produce\n[ ] Lettuce — 1 head\n\nCheck you have\n[ ] Olive oil — 2 tbsp"
    );
});

it('says so when there is nothing to buy', function () {
    expect(GroceryListText::render(GroceryList::empty()))->toBe('Nothing to buy.');
});

it('heads the post with the shop and the nights it covers', function () {
    $text = GroceryListText::post(GroceryList::empty(), theWindowMonToWed());

    expect($text)->toStartWith("Groceries for Sun 11 Jan\nMon 12 Jan to Wed 14 Jan, 3 nights");
});

it('lists the dinners with the household’s own name for each cook', function () {
    // The labeller is an argument for the same reason Pick::explain() takes one:
    // the enum's label reads fine right up until somebody types their name in
    // Settings (MM-4).
    $text = GroceryListText::post(
        GroceryList::empty(),
        theWindowMonToWed(),
        [
            Pick::recipe(aNight('2026-01-12', CookAssignment::Me), aRecipe(1, name: 'Chili')),
            Pick::freezerMeal(aNight('2026-01-13', CookAssignment::Partner), aFreezerMeal(9, name: 'Minestrone')),
            Pick::nightOff(aNight('2026-01-14', CookAssignment::Nobody, note: 'Eating out')),
        ],
        fn (CookAssignment $cook) => match ($cook) {
            CookAssignment::Me => 'Adrian',
            CookAssignment::Partner => 'Milly',
            default => $cook->label(),
        },
    );

    expect($text)->toContain('Mon 12 Jan: Chili (Adrian)')
        ->and($text)->toContain('Tue 13 Jan: Minestrone (from the freezer) (Milly)')
        // Nobody is cooking, so there is nobody to name.
        ->and($text)->toContain('Wed 14 Jan: Nobody cooking — Eating out')
        ->and($text)->not->toContain('(Nobody)');
});

it('names nobody at all rather than risk the wrong name', function () {
    $text = GroceryListText::post(
        GroceryList::empty(),
        theWindowMonToWed(),
        [Pick::recipe(aNight('2026-01-12', CookAssignment::Me), aRecipe(1, name: 'Chili'))],
    );

    expect($text)->toContain('Mon 12 Jan: Chili')
        ->and($text)->not->toContain('Chili (');
});
