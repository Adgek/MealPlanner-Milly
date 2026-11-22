<?php

use App\Settings\TimeOfDay;

/*
 * A unit test: no framework boot, no database (AGENTS.md).
 *
 * This is the gate every time on the Settings page passes through before it is
 * stored, and the readers downstream are unforgiving — HouseholdSettings::at()
 * splits on ":" and casts, and the scheduler is handed the string as-is. A
 * "9:00" or an "09:00:00" that slipped into the settings table would fire a
 * reminder at the wrong hour, and nobody would notice until dinner.
 */

it('leaves a stored time alone', function () {
    expect(TimeOfDay::normalize('17:30'))->toBe('17:30')
        ->and(TimeOfDay::normalize('00:00'))->toBe('00:00')
        ->and(TimeOfDay::normalize('23:59'))->toBe('23:59');
});

it('pads a single-digit hour, because the stored format is HH:MM', function () {
    expect(TimeOfDay::normalize('9:00'))->toBe('09:00')
        ->and(TimeOfDay::normalize('0:05'))->toBe('00:05');
});

it('drops the seconds a time input can send', function () {
    expect(TimeOfDay::normalize('17:30:00'))->toBe('17:30')
        ->and(TimeOfDay::normalize('17:30:45'))->toBe('17:30');
});

it('ignores whitespace around a pasted time', function () {
    expect(TimeOfDay::normalize('  18:00 '))->toBe('18:00');
});

it('refuses anything that is not a time of day', function (?string $input) {
    expect(TimeOfDay::normalize($input))->toBeNull()
        ->and(TimeOfDay::isValid($input))->toBeFalse();
})->with([
    'empty' => '',
    'blank' => '   ',
    'null' => null,
    'hour 24' => '24:00',
    'hour 99' => '99:00',
    'minute 60' => '12:60',
    'no colon' => '1800',
    'twelve hour' => '7pm',
    'words' => 'dinner time',
    'negative' => '-1:00',
    'a date' => '2026-01-14',
]);

it('accepts the times it normalizes', function () {
    expect(TimeOfDay::isValid('9:00'))->toBeTrue()
        ->and(TimeOfDay::isValid('23:59'))->toBeTrue();
});

it('counts minutes since midnight, so two times can be compared', function () {
    expect(TimeOfDay::minutes('00:00'))->toBe(0)
        ->and(TimeOfDay::minutes('20:00'))->toBe(1200)
        ->and(TimeOfDay::minutes('21:00'))->toBe(1260)
        ->and(TimeOfDay::minutes('23:59'))->toBe(1439);
});

it('has no minutes for something that is not a time', function () {
    expect(TimeOfDay::minutes('half past'))->toBeNull()
        ->and(TimeOfDay::minutes(null))->toBeNull();
});

it('orders a prep block by its minutes', function () {
    // What the Settings page compares to reject a block that ends before it starts.
    expect(TimeOfDay::minutes('21:00'))->toBeGreaterThan(TimeOfDay::minutes('20:00'))
        ->and(TimeOfDay::minutes('09:30'))->toBeLessThan(TimeOfDay::minutes('10:00'));
});

it('writes a time the way the household says it', function (string $stored, string $spoken) {
    expect(TimeOfDay::label($stored))->toBe($spoken);
})->with([
    ['18:00', '6:00 pm'],
    ['10:00', '10:00 am'],
    ['17:30', '5:30 pm'],
    // The two that a naive % 12 gets wrong.
    ['00:05', '12:05 am'],
    ['12:00', '12:00 pm'],
    ['00:00', '12:00 am'],
    ['23:59', '11:59 pm'],
    ['09:00', '9:00 am'],
]);

it('shows a value it cannot read rather than pretending it is midnight', function () {
    expect(TimeOfDay::label('whenever'))->toBe('whenever')
        ->and(TimeOfDay::label(null))->toBe('');
});
