<?php

use App\Grocery\GroceryLine;

/*
 * One line of the list, and the question a tick turns on: does the list now ask
 * for more of this than it did when somebody crossed it off?
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

function aLine(?float $quantity, ?string $unit = 'lb', array $meals = ['Chili']): GroceryLine
{
    return new GroceryLine(
        name: 'Ground beef',
        quantity: $quantity,
        unit: $unit,
        section: 'Meat',
        meals: $meals,
    );
}

it('asks for more when the amount has gone up', function () {
    expect(aLine(2.0)->asksForMoreThan(aLine(1.0)))->toBeTrue();
});

it('does not ask for more when the amount has gone down or stayed put', function () {
    expect(aLine(1.0)->asksForMoreThan(aLine(2.0)))->toBeFalse()
        ->and(aLine(2.0)->asksForMoreThan(aLine(2.0)))->toBeFalse();
});

it('ignores a difference smaller than the column can hold', function () {
    expect(aLine(2.001)->asksForMoreThan(aLine(2.0)))->toBeFalse();
});

it('asks for more when an amount appears and another dinner wants it too', function () {
    // The line read "Ground beef" with no amount and was ticked. A second night
    // now wants a pound of it, so "we have enough" was true of one dinner's worth
    // and is not true of two — and an unmeasured need adds nothing to the number,
    // so the line would otherwise read "1 lb", crossed off, for two dinners.
    $before = aLine(null, meals: ['Chili']);
    $after = aLine(1.0, meals: ['Chili', 'White Boy Tacos']);

    expect($after->asksForMoreThan($before))->toBeTrue();
});

it('does not ask for more when the same dinner merely got specific', function () {
    // One meal, which used to say "some beef" and now says a pound. Nothing more
    // is needed than was needed before.
    $before = aLine(null, meals: ['Chili']);
    $after = aLine(1.0, meals: ['Chili']);

    expect($after->asksForMoreThan($before))->toBeFalse();
});

it('does not ask for more when the amount stops being given', function () {
    expect(aLine(null, meals: ['Chili', 'White Boy Tacos'])->asksForMoreThan(aLine(1.0)))->toBeFalse();
});
