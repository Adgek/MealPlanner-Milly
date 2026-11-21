<?php

use App\Livewire\Plan;
use Illuminate\Support\Carbon;

/*
 * A unit test: no framework boot, no database. This is the shape planning logic
 * (MM-6's randomizer, MM-7's grocery list) should be tested in — see AGENTS.md.
 */

afterEach(fn () => Carbon::setTestNow());

it('formats the kitchen clock the way the wall clock reads', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-14 18:00:05'));

    expect((new Plan)->householdTime())->toBe('Wed 14 Jan, 6:00:05 pm');
});

it('follows the clock forward', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-14 18:00:05'));
    $before = (new Plan)->householdTime();

    Carbon::setTestNow(Carbon::parse('2026-01-14 18:00:10'));

    expect((new Plan)->householdTime())->not->toBe($before);
});
