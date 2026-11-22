<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Planning\MealPool;
use App\Planning\NightPicker;
use App\Planning\Pick;
use App\Planning\PlanningRules;
use App\Planning\SeededRandom;
use App\Planning\WindowPicker;

/*
 * The rules that only exist across a whole window: nothing twice, one portion per
 * freezer meal, locked nights kept, and one dead-end night not taking the rest of
 * the plan down with it.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

function aWindowPicker(?PlanningRules $rules = null): WindowPicker
{
    return new WindowPicker(new NightPicker($rules ?? theRules()));
}

/**
 * @return array<int, string>
 */
function dinners(array $picks): array
{
    return array_map(fn (Pick $pick) => (string) $pick->describe(), $picks);
}

it('never uses the same recipe twice in one window', function () {
    $picks = aWindowPicker()->pick(
        [aNight('2026-01-12'), aNight('2026-01-13'), aNight('2026-01-14')],
        new MealPool([
            aRecipe(1, name: 'Bulgogi'),
            aRecipe(2, name: 'Tacos'),
            aRecipe(3, name: 'Chili'),
        ]),
        new SeededRandom(7),
    );

    expect($picks)->toHaveCount(3)
        ->and(array_unique(dinners($picks)))->toHaveCount(3);
});

it('plans the nights in date order, however they arrive', function () {
    $picks = aWindowPicker()->pick(
        [aNight('2026-01-14'), aNight('2026-01-12'), aNight('2026-01-13')],
        new MealPool([aRecipe(1), aRecipe(2), aRecipe(3)]),
        new SeededRandom(7),
    );

    expect(array_map(fn (Pick $pick) => $pick->night->date->toDateString(), $picks))
        ->toBe(['2026-01-12', '2026-01-13', '2026-01-14']);
});

it('marks a night nothing fits and plans the rest anyway', function () {
    // Two nights need a quick meal and there is only one; the Wednesday project
    // night is fine.
    $picks = aWindowPicker()->pick(
        [
            aNight('2026-01-12', CookAssignment::Partner, EffortTier::Chaos15),
            aNight('2026-01-13', CookAssignment::Partner, EffortTier::Chaos15),
            aNight('2026-01-14', CookAssignment::Me, EffortTier::Project60),
        ],
        new MealPool([
            aRecipe(1, CookableBy::Partner, EffortTier::Chaos15, name: 'Quick pasta'),
            aRecipe(2, CookableBy::Me, EffortTier::Project60, name: 'Chili'),
        ]),
        new SeededRandom(3),
    );

    expect($picks[0]->mealName)->toBe('Quick pasta')
        ->and($picks[1]->selection)->toBe(NightSelection::NothingFits)
        ->and($picks[1]->note)->toContain('already spoken for this window')
        ->and($picks[2]->mealName)->toBe('Chili');
});

it('spends one freezer portion per night and stops when the box is empty', function () {
    $picks = aWindowPicker()->pick(
        [
            aNight('2026-01-12', CookAssignment::Partner, EffortTier::Chaos15),
            aNight('2026-01-13', CookAssignment::Partner, EffortTier::Chaos15),
        ],
        new MealPool([], [aFreezerMeal(1, 4, name: 'Frozen minestrone')]),
        new SeededRandom(5),
    );

    // Four portions in the box, but a meal is not repeated inside a window, so
    // the second night has nothing left to take.
    expect($picks[0]->selection)->toBe(NightSelection::FreezerItem)
        ->and($picks[1]->selection)->toBe(NightSelection::NothingFits);
});

it('keeps a locked night exactly as it is', function () {
    $locked = Pick::recipe(
        aNight('2026-01-13', CookAssignment::Me),
        aRecipe(2, name: 'Tacos'),
    );

    $picks = aWindowPicker()->pick(
        [aNight('2026-01-12'), aNight('2026-01-14')],
        new MealPool([aRecipe(1, name: 'Bulgogi'), aRecipe(2, name: 'Tacos'), aRecipe(3, name: 'Chili')]),
        new SeededRandom(11),
        keep: [$locked],
    );

    // The locked night is not re-rolled, and its meal is out of the draw for the
    // nights that are.
    expect($picks)->toHaveCount(2)
        ->and(dinners($picks))->not->toContain('Tacos');
});

it('leaves a locked night its freezer portion', function () {
    // The portion was taken out of the stash when the night was planned, so the
    // window must not count it again: with one portion left in the box, the open
    // night can still have it.
    $locked = Pick::freezerMeal(
        aNight('2026-01-12', CookAssignment::Partner, EffortTier::Chaos15),
        aFreezerMeal(1, 1, name: 'Frozen minestrone'),
    );

    $picks = aWindowPicker()->pick(
        [aNight('2026-01-13', CookAssignment::Partner, EffortTier::Chaos15)],
        new MealPool([], [aFreezerMeal(2, 1, name: 'Frozen chili')]),
        new SeededRandom(2),
        keep: [$locked],
    );

    expect($picks[0]->mealName)->toBe('Frozen chili');
});

it('plans a nobody night as a night off without spending anything', function () {
    $picks = aWindowPicker()->pick(
        [
            aNight('2026-01-12', CookAssignment::Nobody, note: 'Out for dinner'),
            aNight('2026-01-13'),
        ],
        new MealPool([aRecipe(1, name: 'Bulgogi')]),
        new SeededRandom(1),
    );

    expect($picks[0]->selection)->toBe(NightSelection::NightOff)
        ->and($picks[0]->describe())->toBe('Nobody cooking — Out for dinner')
        // The recipe is still there for the night that does cook.
        ->and($picks[1]->mealName)->toBe('Bulgogi');
});

it('comes out the same way every time for a given seed', function () {
    $window = fn (int $seed) => dinners(aWindowPicker()->pick(
        [aNight('2026-01-12'), aNight('2026-01-13'), aNight('2026-01-14')],
        new MealPool([aRecipe(1), aRecipe(2), aRecipe(3), aRecipe(4), aRecipe(5), aRecipe(6)]),
        new SeededRandom($seed),
    ));

    expect($window(2026))->toBe($window(2026))
        ->and($window(2026))->not->toBe($window(1999));
});

it('plans a four-night window as readily as a three-night one', function () {
    $picks = aWindowPicker()->pick(
        [aNight('2026-01-15'), aNight('2026-01-16'), aNight('2026-01-17'), aNight('2026-01-18')],
        new MealPool([aRecipe(1), aRecipe(2), aRecipe(3), aRecipe(4)]),
        new SeededRandom(4),
    );

    expect($picks)->toHaveCount(4)
        ->and(array_unique(dinners($picks)))->toHaveCount(4);
});
