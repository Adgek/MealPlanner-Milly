<?php

use App\Planning\CalendarDate;
use Carbon\CarbonImmutable;

/*
 * A night is a calendar date, not an instant (docs/decisions.md). Everything the
 * planner does rests on that, so the two directions are pinned down here: reading
 * a date as the day it names, and asking which day an instant fell on in the
 * kitchen. Get the second one wrong and the whole window slides by a day.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

afterEach(fn () => CarbonImmutable::setTestNow());

it('reads a date as the day it names, whatever time it carries', function () {
    expect(CalendarDate::of(CarbonImmutable::parse('2026-01-14 23:45:12', 'America/Toronto'))->toDateString())
        ->toBe('2026-01-14')
        ->and(CalendarDate::of(CarbonImmutable::parse('2026-01-14 00:30:00', 'UTC'))->toDateString())
        ->toBe('2026-01-14');
});

it('strips the time, so two dates are a whole number of days apart', function () {
    $date = CalendarDate::of(CarbonImmutable::parse('2026-01-14 17:05:33', 'Australia/Sydney'));

    expect($date->format('H:i:s'))->toBe('00:00:00')
        ->and($date->timezoneName)->toBe('UTC');
});

it('reads a plain DateTime too', function () {
    expect(CalendarDate::of(new DateTimeImmutable('2026-03-08'))->toDateString())->toBe('2026-03-08');
});

it('asks the household clock which day an instant fell on', function () {
    // 03:30 UTC on the 15th is still the evening of the 14th in Toronto. A plan
    // built on the server's date would be a night out of step.
    $lateEvening = CarbonImmutable::parse('2026-01-15 03:30:00', 'UTC');

    expect(CalendarDate::in($lateEvening, 'America/Toronto')->toDateString())->toBe('2026-01-14')
        ->and(CalendarDate::in($lateEvening, 'UTC')->toDateString())->toBe('2026-01-15');
});

it('crosses the day boundary the other way too', function () {
    // The time zone is a setting, so it can just as well be ahead of UTC: half
    // past midnight in Berlin is still the previous day on the server.
    $justAfterMidnight = CarbonImmutable::parse('2026-07-04 00:30:00', 'Europe/Berlin');

    expect(CalendarDate::in($justAfterMidnight, 'Europe/Berlin')->toDateString())->toBe('2026-07-04')
        ->and(CalendarDate::in($justAfterMidnight, 'UTC')->toDateString())->toBe('2026-07-03');
});

it('knows today on the kitchen clock', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-14 23:30:00', 'America/Toronto'));

    expect(CalendarDate::today('America/Toronto')->toDateString())->toBe('2026-01-14')
        // The same moment, where the server thinks it already is tomorrow.
        ->and(CalendarDate::today('UTC')->toDateString())->toBe('2026-01-15');
});

it('counts whole days between two dates, in either direction', function () {
    $monday = CalendarDate::parse('2026-01-12');
    $thursday = CalendarDate::parse('2026-01-15');

    expect(CalendarDate::daysBetween($monday, $thursday))->toBe(3)
        ->and(CalendarDate::daysBetween($thursday, $monday))->toBe(-3)
        ->and(CalendarDate::daysBetween($monday, $monday))->toBe(0);
});

it('counts days across a daylight-saving change without losing an hour', function () {
    // Toronto springs forward on 8 March 2026. Counted as instants in that zone,
    // these two dates are 23 hours apart, which floors to 0 whole days.
    expect(CalendarDate::daysBetween(
        CarbonImmutable::parse('2026-03-07 00:00', 'America/Toronto'),
        CarbonImmutable::parse('2026-03-08 00:00', 'America/Toronto'),
    ))->toBe(1);
});

it('knows a night has gone by, on the kitchen clock and not the server’s', function () {
    // 8pm on the 14th in Toronto, which is already 1am on the 15th in UTC. The
    // fence the planner keeps around a night that was cooked turns on this: at
    // this moment the household can still change their mind about tonight.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-14 20:00:00', 'America/Toronto'));

    expect(CalendarDate::hasPassed(CalendarDate::parse('2026-01-13'), 'America/Toronto'))->toBeTrue()
        // Tonight is not the past.
        ->and(CalendarDate::hasPassed(CalendarDate::parse('2026-01-14'), 'America/Toronto'))->toBeFalse()
        ->and(CalendarDate::hasPassed(CalendarDate::parse('2026-01-15'), 'America/Toronto'))->toBeFalse()
        // On the server's clock it is already tomorrow, and tonight would be over.
        ->and(CalendarDate::hasPassed(CalendarDate::parse('2026-01-14'), 'UTC'))->toBeTrue();
});
