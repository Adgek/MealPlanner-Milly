<?php

use App\Livewire\Plan;
use Illuminate\Support\Carbon;

/*
 * A unit test: no framework boot, no database. This is the shape planning logic
 * (MM-6's randomizer, MM-7's grocery list) should be tested in — see AGENTS.md.
 *
 * householdTime() takes the time zone as an argument here. In a real request it
 * reads the household's 'time_zone' setting instead, which needs a database; the
 * conversion itself is the part worth pinning down, and it doesn't.
 */

afterEach(fn () => Carbon::setTestNow());

it('renders a stored instant as the kitchen wall clock', function () {
    // Instants are stored in UTC (MM-3). 18:00 UTC is 1pm in Toronto in January.
    Carbon::setTestNow(Carbon::parse('2026-01-14 18:00:05', 'UTC'));

    expect((new Plan)->householdTime('America/Toronto'))->toBe('Wed 14 Jan, 1:00:05 pm');
});

it('applies the offset in force on the day, not a fixed one', function () {
    // Toronto is UTC-5 in January and UTC-4 in July. The same wall-clock UTC time
    // has to land an hour apart, or a summer reminder would fire an hour out.
    Carbon::setTestNow(Carbon::parse('2026-01-14 18:00:00', 'UTC'));
    $winter = (new Plan)->householdTime('America/Toronto');

    Carbon::setTestNow(Carbon::parse('2026-07-14 18:00:00', 'UTC'));
    $summer = (new Plan)->householdTime('America/Toronto');

    expect($winter)->toContain('1:00:00 pm')
        ->and($summer)->toContain('2:00:00 pm');
});

it('can cross a day boundary', function () {
    // 03:30 UTC is still the previous evening in the kitchen. A plan page that got
    // this wrong would show tomorrow's dinner after 7pm.
    Carbon::setTestNow(Carbon::parse('2026-01-15 03:30:00', 'UTC'));

    expect((new Plan)->householdTime('America/Toronto'))->toBe('Wed 14 Jan, 10:30:00 pm');
});

it('follows the clock forward', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-14 18:00:05', 'UTC'));
    $before = (new Plan)->householdTime('America/Toronto');

    Carbon::setTestNow(Carbon::parse('2026-01-14 18:00:10', 'UTC'));

    expect((new Plan)->householdTime('America/Toronto'))->not->toBe($before);
});
