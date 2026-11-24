<?php

use App\Vault\Removal;
use App\Vault\UsedNight;

/*
 * What the household is told after a removal (MM-15).
 *
 * This is the banner, and it is built from what actually happened rather than
 * from what was going to happen: a re-pick that finds nothing else fitting keeps
 * the meal on the night (MM-6), and a banner written from the intention would
 * say the dinner changed when the plan still reads the same.
 *
 * Plain values, so the wording is tested with no database behind it (AGENTS.md).
 */

/**
 * One night of a plan, as a removal reports it.
 */
function aRemovedNight(string $date, int $id = 1): UsedNight
{
    return new UsedNight(id: $id, date: new DateTimeImmutable($date));
}

it('says what it did, and nothing more, for a meal nothing pointed at', function () {
    $removal = new Removal(name: 'Chili');

    expect($removal->describe())
        ->toBe('Chili is out of the vault, and the picker will not choose it again.');
});

it('says a freezer meal left the stash, not the vault', function () {
    $removal = new Removal(name: 'Frozen minestrone', fromTheStash: true);

    expect($removal->describe())
        ->toBe('Frozen minestrone is out of the stash, and the picker will not plan it again.');
});

it('reports the nights that really got a different dinner', function () {
    $removal = new Removal(name: 'Chili', changed: [aRemovedNight('2026-01-20')]);

    expect($removal->describe())->toContain('Tue 20 Jan now has a different dinner.');
});

it('counts several changed nights in the plural', function () {
    $removal = new Removal(name: 'Chili', changed: [
        aRemovedNight('2026-01-20', id: 1),
        aRemovedNight('2026-01-22', id: 2),
    ]);

    expect($removal->describe())->toContain('Tue 20 Jan and Thu 22 Jan now have different dinners.');
});

it('says plainly when a night kept the meal because nothing else fitted', function () {
    $removal = new Removal(name: 'Chili', kept: [aRemovedNight('2026-01-20')]);

    expect($removal->describe())
        ->toContain('Tue 20 Jan kept it: nothing else in the vault fits that night.')
        // Something the household can do about it.
        ->toContain('Add a recipe that fits, or pick the dinner yourself on the plan page.');
});

it('never says a dinner changed on a night that kept the meal', function () {
    $removal = new Removal(name: 'Chili', kept: [aRemovedNight('2026-01-20')]);

    expect($removal->describe())->not->toContain('different dinner');
});

it('sends a kept freezer night to the stash rather than the vault', function () {
    $removal = new Removal(name: 'Frozen minestrone', fromTheStash: true, kept: [aRemovedNight('2026-01-20')]);

    expect($removal->describe())->toContain('nothing else in the stash fits that night');
});

it('says a locked night keeps it, and why', function () {
    $removal = new Removal(name: 'Chili', locked: [aRemovedNight('2026-01-23')]);

    expect($removal->describe())->toContain('Fri 23 Jan keeps it: that night is locked.');
});

it('says how many portions went back into the count', function () {
    $removal = new Removal(name: 'Frozen minestrone', fromTheStash: true, portionsHandedBack: 2);

    expect($removal->describe())->toContain('2 portions went back into the count.');
});

it('counts one portion in the singular', function () {
    expect((new Removal(name: 'Frozen minestrone', fromTheStash: true, portionsHandedBack: 1))->describe())
        ->toContain('1 portion went back into the count.');
});

it('promises nothing cooked was lost', function () {
    expect((new Removal(name: 'Chili', hadBeenEaten: true))->describe())
        ->toContain('Nothing you have cooked was lost.');
});

it('says every true thing at once, in one order', function () {
    $removal = new Removal(
        name: 'Chili',
        changed: [aRemovedNight('2026-01-20', id: 1)],
        kept: [aRemovedNight('2026-01-21', id: 2)],
        locked: [aRemovedNight('2026-01-23', id: 3)],
        hadBeenEaten: true,
    );

    expect($removal->describe())->toContain('Chili is out of the vault')
        ->and($removal->describe())->toContain('Tue 20 Jan now has a different dinner.')
        ->and($removal->describe())->toContain('Wed 21 Jan kept it')
        ->and($removal->describe())->toContain('Fri 23 Jan keeps it: that night is locked.')
        ->and($removal->describe())->toContain('Nothing you have cooked was lost.');
});
