<?php

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Planning\Exceptions\PlanningException;
use App\Planning\WeeklyCookSchedule;

/*
 * Each night, the ticket says, takes who cooks and how much time there is from
 * the weekly schedule in Settings — and then applies that date's one-off
 * override, if there is one. These are the two halves of that.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

it('reads who cooks and how long from the weekly schedule', function () {
    $week = aWeek([
        // My partner cooks alone on the Tuesday, with half an hour.
        Weekday::Tuesday->value => [CookAssignment::Partner, EffortTier::Workhorse30],
        // Nobody cooks on the Friday.
        Weekday::Friday->value => [CookAssignment::Nobody, EffortTier::Chaos15],
    ]);

    // 12 January 2026 is a Monday, the 13th a Tuesday, the 16th a Friday.
    expect($week->nightOn(aDate('2026-01-12'))->cook)->toBe(CookAssignment::Together)
        ->and($week->nightOn(aDate('2026-01-13'))->cook)->toBe(CookAssignment::Partner)
        ->and($week->nightOn(aDate('2026-01-13'))->timeBudget)->toBe(EffortTier::Workhorse30)
        ->and($week->nightOn(aDate('2026-01-13'))->isPartnerSolo())->toBeTrue()
        ->and($week->nightOn(aDate('2026-01-16'))->cooks())->toBeFalse();
});

it('reads the same weekday the same way whichever week it is', function () {
    $week = aWeek([Weekday::Tuesday->value => [CookAssignment::Partner, EffortTier::Chaos15]]);

    expect($week->nightOn(aDate('2026-01-13'))->cook)
        ->toBe($week->nightOn(aDate('2026-03-24'))->cook)
        ->and($week->nightOn(aDate('2026-03-24'))->weekday())->toBe(Weekday::Tuesday);
});

it('refuses a schedule with a weekday missing', function () {
    // Seven rows, or the planner would be deciding who cooks on a Sunday.
    $sixDays = array_map(fn (Weekday $weekday) => [
        'weekday' => $weekday,
        'cook' => CookAssignment::Together,
        'timeBudget' => EffortTier::Workhorse30,
    ], array_filter(Weekday::week(), fn (Weekday $day) => $day !== Weekday::Sunday));

    expect(fn () => new WeeklyCookSchedule($sixDays))
        ->toThrow(PlanningException::class, 'no Sunday');
});

it('gives a brief for every night of a window', function () {
    $window = aShoppingCalendar()->windowFor(aDate('2026-01-11'));

    $briefs = aWeek()->nightsOf($window);

    expect($briefs)->toHaveCount(3)
        ->and(array_map(fn ($brief) => $brief->date->toDateString(), $briefs))
        ->toBe(['2026-01-12', '2026-01-13', '2026-01-14']);
});

it('applies a one-off override for a single date', function () {
    $night = aWeek()->nightOn(aDate('2026-01-13'));

    $overridden = $night->overriddenBy(
        cook: CookAssignment::Partner,
        timeBudget: EffortTier::Chaos15,
        note: 'I am travelling',
    );

    expect($overridden->cook)->toBe(CookAssignment::Partner)
        ->and($overridden->timeBudget)->toBe(EffortTier::Chaos15)
        ->and($overridden->overridesSchedule)->toBeTrue()
        ->and($overridden->note)->toBe('I am travelling')
        // The schedule itself is untouched: this is one date, not the normal week.
        ->and($night->cook)->toBe(CookAssignment::Together)
        ->and($night->overridesSchedule)->toBeFalse();
});

it('overrides one thing at a time', function () {
    $night = aWeek()->nightOn(aDate('2026-01-13'));

    expect($night->overriddenBy(cook: CookAssignment::Nobody)->timeBudget)
        ->toBe($night->timeBudget)
        ->and($night->overriddenBy(timeBudget: EffortTier::Project60)->cook)
        ->toBe($night->cook)
        // A note on its own is still a departure from the normal week.
        ->and($night->overriddenBy(note: 'Kids are out')->overridesSchedule)
        ->toBeTrue()
        ->and($night->overriddenBy(note: 'Kids are out')->cook)
        ->toBe(CookAssignment::Together);
});

it('is not an override when nothing was given', function () {
    $night = aWeek()->nightOn(aDate('2026-01-13'));

    expect($night->overriddenBy())->toBe($night)
        ->and($night->overriddenBy()->overridesSchedule)->toBeFalse();
});

it('narrows a night without forgetting it was overridden', function () {
    $night = aWeek()
        ->nightOn(aDate('2026-01-13'))
        ->overriddenBy(cook: CookAssignment::Partner, note: 'I am travelling');

    $narrowed = $night->withTimeBudget(EffortTier::Chaos15);

    expect($narrowed->timeBudget)->toBe(EffortTier::Chaos15)
        ->and($narrowed->overridesSchedule)->toBeTrue()
        ->and($narrowed->note)->toBe('I am travelling')
        ->and($night->withTimeBudget($night->timeBudget))->toBe($night);
});

it('labels a night the way a plan reads it', function () {
    expect(aWeek()->nightOn(aDate('2026-01-12'))->label())->toBe('Mon 12 Jan');
});
