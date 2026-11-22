<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Enums\Weekday;
use Illuminate\Support\Carbon;

/*
 * Unit tests: no framework, no database (AGENTS.md). These enums are the shared
 * vocabulary the picker, the pages and the Slack posts all speak, so the stored
 * values are worth pinning down — changing one silently invalidates rows that
 * are already in the database.
 */

it('names who can cook a recipe', function () {
    expect(array_map(fn ($case) => $case->value, CookableBy::cases()))
        ->toBe(['me', 'partner', 'both']);
});

it('names who cooks on a night, including nobody', function () {
    expect(array_map(fn ($case) => $case->value, CookAssignment::cases()))
        ->toBe(['me', 'partner', 'together', 'nobody']);
});

it('knows a night off has no cook', function () {
    expect(CookAssignment::Nobody->cooks())->toBeFalse()
        ->and(CookAssignment::Together->cooks())->toBeTrue()
        ->and(CookAssignment::Me->cooks())->toBeTrue()
        ->and(CookAssignment::Partner->cooks())->toBeTrue();
});

it('names the three effort tiers', function () {
    expect(array_map(fn ($case) => $case->value, EffortTier::cases()))
        ->toBe(['15_min_chaos', '30_min_workhorse', '60_min_project']);
});

it('reads an effort tier as the least time it needs', function () {
    // The 60-minute tier is "60 or more", so this is a floor.
    expect(EffortTier::Chaos15->minutes())->toBe(15)
        ->and(EffortTier::Workhorse30->minutes())->toBe(30)
        ->and(EffortTier::Project60->minutes())->toBe(60);
});

it('labels an effort tier the way the plan reads', function () {
    expect(EffortTier::Chaos15->label())->toBe('15-min chaos')
        ->and(EffortTier::Workhorse30->label())->toBe('30-min workhorse')
        ->and(EffortTier::Project60->label())->toBe('60-min+ project');
});

it('names what can be chosen for a night', function () {
    // nothing_fits is a decision too: the picker looked and the rules ruled every
    // recipe out (MM-6). Kept apart from a null selection, which means "not yet".
    expect(array_map(fn ($case) => $case->value, NightSelection::cases()))
        ->toBe(['recipe', 'freezer_item', 'night_off', 'nothing_fits']);
});

it('knows which selections are actually dinner', function () {
    expect(NightSelection::Recipe->hasMeal())->toBeTrue()
        ->and(NightSelection::FreezerItem->hasMeal())->toBeTrue()
        ->and(NightSelection::NightOff->hasMeal())->toBeFalse()
        ->and(NightSelection::NothingFits->hasMeal())->toBeFalse();
});

it('lists the week Monday first', function () {
    expect(array_map(fn ($day) => $day->value, Weekday::week()))
        ->toBe(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
});

it('numbers weekdays the ISO way and the Carbon way', function () {
    // Two conventions that disagree, which is exactly why the column stores names.
    expect(Weekday::Monday->isoNumber())->toBe(1)
        ->and(Weekday::Sunday->isoNumber())->toBe(7)
        ->and(Weekday::Monday->carbonDayOfWeek())->toBe(1)
        ->and(Weekday::Sunday->carbonDayOfWeek())->toBe(0)
        ->and(Weekday::Saturday->carbonDayOfWeek())->toBe(6);
});

it('agrees with Carbon on the weekday of a date', function (string $date, Weekday $expected) {
    expect(Weekday::fromDate(Carbon::parse($date)))->toBe($expected)
        // And the number it reports matches what Carbon says for the same date.
        ->and($expected->carbonDayOfWeek())->toBe(Carbon::parse($date)->dayOfWeek);
})->with([
    // The default shopping days, and the edges of the week.
    ['2026-01-11', Weekday::Sunday],
    ['2026-01-14', Weekday::Wednesday],
    ['2026-01-12', Weekday::Monday],
    ['2026-01-17', Weekday::Saturday],
]);

it('reads the weekday of a plain DateTime too', function () {
    expect(Weekday::fromDate(new DateTimeImmutable('2026-01-11')))->toBe(Weekday::Sunday);
});
