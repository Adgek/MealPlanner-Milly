<?php

use App\Vault\MealUsage;
use App\Vault\UsedNight;

/*
 * What removing a recipe or a freezer meal is allowed to do, and what the
 * household is told before they press the button (MM-15).
 *
 * These are the rules, so they are tested as plain values with no database
 * behind them (AGENTS.md). The two that matter most:
 *
 *   - nothing is deleted outright while anything points at it, because
 *     meal_history cascades and the plan's foreign keys null out;
 *   - a night that has gone by, and a night the household locked, are never
 *     re-picked.
 */

/**
 * One night of a plan pointing at the meal, as MealUsage carries it.
 */
function aPlannedNight(
    string $date,
    bool $hasPassed = false,
    bool $isLocked = false,
    bool $isCurrentPlan = false,
    int $id = 1,
): UsedNight {
    return new UsedNight(
        id: $id,
        date: new DateTimeImmutable($date),
        hasPassed: $hasPassed,
        isLocked: $isLocked,
        isCurrentPlan: $isCurrentPlan,
    );
}

/*
|--------------------------------------------------------------------------
| May the row go?
|--------------------------------------------------------------------------
*/

it('deletes a recipe nothing points at', function () {
    $usage = new MealUsage(name: 'Mistake');

    expect($usage->isUnused())->toBeTrue()
        ->and($usage->canBeDeletedForGood())->toBeTrue()
        ->and($usage->whyItCannotBeDeleted())->toBeNull()
        ->and($usage->warnings())->toBe([]);
});

it('refuses to delete a meal the household has cooked', function () {
    $usage = new MealUsage(name: 'Chili', timesCooked: 7);

    expect($usage->hasBeenEaten())->toBeTrue()
        ->and($usage->canBeDeletedForGood())->toBeFalse()
        ->and($usage->whyItCannotBeDeleted())
        ->toBe('You have eaten this, and deleting it would take that record with it.');
});

it('counts a night that has gone by as having been eaten, even with no history row', function () {
    // The plan is the only record that the night happened (MM-6).
    $usage = new MealUsage(name: 'Chili', nights: [aPlannedNight('2026-01-10', hasPassed: true)]);

    expect($usage->hasBeenEaten())->toBeTrue()
        ->and($usage->canBeDeletedForGood())->toBeFalse();
});

it('refuses to delete a meal a plan still points at', function () {
    $usage = new MealUsage(name: 'Chili', nights: [aPlannedNight('2026-01-20')]);

    expect($usage->hasBeenEaten())->toBeFalse()
        ->and($usage->canBeDeletedForGood())->toBeFalse()
        ->and($usage->whyItCannotBeDeleted())->toBe('It is on a plan right now.');
});

it('refuses to delete a freezer meal a project restocks', function () {
    $usage = new MealUsage(name: 'Frozen minestrone', restockedBy: ['Big batch minestrone']);

    expect($usage->canBeDeletedForGood())->toBeFalse()
        ->and($usage->whyItCannotBeDeleted())->toBe('Big batch minestrone still restocks it.');
});

/*
|--------------------------------------------------------------------------
| Which nights change
|--------------------------------------------------------------------------
*/

it('picks again only for the nights it still may', function () {
    $usage = new MealUsage(name: 'Chili', nights: [
        aPlannedNight('2026-01-10', hasPassed: true, id: 1),
        aPlannedNight('2026-01-20', isLocked: true, id: 2),
        aPlannedNight('2026-01-22', id: 3),
    ]);

    expect($usage->nightIdsToRepick())->toBe([3])
        ->and($usage->pastNights())->toHaveCount(1)
        ->and($usage->lockedNights())->toHaveCount(1)
        ->and($usage->upcomingNights())->toHaveCount(2);
});

it('never picks again for a night that has gone by, locked or not', function (bool $isLocked) {
    $usage = new MealUsage(name: 'Chili', nights: [
        aPlannedNight('2026-01-10', hasPassed: true, isLocked: $isLocked),
    ]);

    expect($usage->nightIdsToRepick())->toBe([]);
})->with([true, false]);

/*
|--------------------------------------------------------------------------
| What the warning says
|--------------------------------------------------------------------------
*/

it('names the night that loses its dinner', function () {
    $usage = new MealUsage(name: 'Chili', nights: [aPlannedNight('2026-01-20')]);

    expect($usage->warnings())
        ->toBe(['It is dinner on Tue 20 Jan, so removing it picks something else for that night.']);
});

it('names several nights in one sentence', function () {
    $usage = new MealUsage(name: 'Chili', nights: [
        aPlannedNight('2026-01-20', id: 1),
        aPlannedNight('2026-01-22', id: 2),
    ]);

    expect($usage->warnings()[0])
        ->toBe('It is dinner on Tue 20 Jan and Thu 22 Jan, so removing it picks something else for those nights.');
});

it('says when the night is on the plan that is out now', function () {
    $usage = new MealUsage(name: 'Chili', nights: [aPlannedNight('2026-01-20', isCurrentPlan: true)]);

    expect($usage->warnings()[0])->toContain('on the plan that is out now');
});

it('says that a locked night keeps the meal', function () {
    $usage = new MealUsage(name: 'Chili', nights: [aPlannedNight('2026-01-23', isLocked: true)]);

    expect($usage->warnings())->toBe([
        'Fri 23 Jan is locked, so it keeps this meal until you unlock it on the plan page.',
    ]);
});

it('says what the plan records as already eaten', function () {
    $usage = new MealUsage(name: 'Chili', nights: [aPlannedNight('2026-01-10', hasPassed: true)]);

    expect($usage->warnings())->toBe([
        'The plan says you ate it on Sat 10 Jan. That night stays as it is.',
    ]);
});

it('promises the cooking history survives', function () {
    $usage = new MealUsage(name: 'Chili', timesCooked: 7);

    expect($usage->warnings()[0])
        ->toContain('You have cooked it 7 times')
        ->toContain('Removing it keeps that record');
});

it('counts one cooking in the singular', function () {
    expect((new MealUsage(name: 'Chili', timesCooked: 1))->warnings()[0])
        ->toContain('cooked it 1 time.');
});

it('caps a long list of nights rather than printing a paragraph', function () {
    $nights = [];

    foreach (range(1, 6) as $day) {
        $nights[] = aPlannedNight(sprintf('2026-01-%02d', $day), hasPassed: true, id: $day);
    }

    expect((new MealUsage(name: 'Chili', nights: $nights))->warnings()[0])
        ->toContain('Thu 1 Jan, Fri 2 Jan, Sat 3 Jan and 3 other nights');
});

it('says how many portions are still in the stash', function () {
    $usage = new MealUsage(name: 'Frozen minestrone', portionsLeft: 3);

    expect($usage->warnings()[0])
        ->toContain('3 portions are still counted in the stash')
        ->toContain('the picker stops planning them');
});

it('says nothing about an empty box', function () {
    expect((new MealUsage(name: 'Frozen minestrone', portionsLeft: 0))->warnings())->toBe([]);
});

it('names the projects that will go on restocking it', function () {
    $usage = new MealUsage(
        name: 'Frozen minestrone',
        restockedBy: ['Big batch minestrone', 'Soup Sunday'],
    );

    expect($usage->warnings())->toBe([
        'Big batch minestrone and Soup Sunday restock it, and will go on doing so until you change those projects.',
    ]);
});

it('says every true thing at once', function () {
    $usage = new MealUsage(
        name: 'Frozen minestrone',
        nights: [
            aPlannedNight('2026-01-10', hasPassed: true, id: 1),
            aPlannedNight('2026-01-20', id: 2),
        ],
        restockedBy: ['Big batch minestrone'],
        portionsLeft: 2,
    );

    expect($usage->warnings())->toHaveCount(4);
});
