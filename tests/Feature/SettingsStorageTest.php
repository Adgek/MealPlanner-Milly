<?php

use App\Enums\CookAssignment;
use App\Enums\Weekday;
use App\Models\Setting;
use App\Settings\HouseholdSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/*
 * Household values live in Settings, not in code (AGENTS.md). This covers the
 * key/value store underneath and the typed reads on top of it.
 */

it('writes a setting and reads it back', function () {
    Setting::write('dinner_time', '19:30');

    expect(Setting::read('dinner_time'))->toBe('19:30');
});

it('updates a setting rather than adding a second row', function () {
    Setting::write('dinner_time', '18:00');
    Setting::write('dinner_time', '19:00');

    expect(Setting::where('key', 'dinner_time')->count())->toBe(1)
        ->and(Setting::read('dinner_time'))->toBe('19:00');
});

it('refuses two rows for one key', function () {
    Setting::factory()->of('dinner_time', '18:00')->create();

    expect(fn () => Setting::factory()->of('dinner_time', '19:00')->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('stores numbers, strings, booleans and structures in the same column', function () {
    Setting::write('a_number', 2);
    Setting::write('a_string', 'America/Toronto');
    Setting::write('a_boolean', true);
    Setting::write('a_structure', ['weekday' => 'monday', 'start' => '20:00']);

    expect(Setting::read('a_number'))->toBe(2)
        ->and(Setting::read('a_string'))->toBe('America/Toronto')
        ->and(Setting::read('a_boolean'))->toBeTrue()
        // toEqual, not toBe: MySQL's JSON type normalises object key order, so a
        // structure comes back with the same pairs but not the same ordering.
        ->and(Setting::read('a_structure'))->toEqual(['weekday' => 'monday', 'start' => '20:00']);
});

it('falls back to a default when the setting is missing', function () {
    expect(Setting::read('never_set', 'fallback'))->toBe('fallback');
});

it('reads every setting in one query for the settings page', function () {
    Setting::write('dinner_time', '18:00');
    Setting::write('plan_post_time', '19:00');

    expect(Setting::map()->all())->toBe(['dinner_time' => '18:00', 'plan_post_time' => '19:00']);
});

it('offers a typed default for every starting setting', function () {
    // The rows under "Starting settings" in docs/decisions.md.
    expect(array_keys(HouseholdSettings::defaults()))->toBe([
        'cook_names',
        'time_zone',
        'shopping_times',
        'prep_block',
        'dinner_time',
        'plan_post_time',
        'daily_dinner_reminder_time',
        'raw_split_fridge_life_days',
        'low_freezer_stash_portions',
    ]);
});

it('reads the starting settings without any rows in the table', function () {
    // A half-seeded database still has to behave.
    expect(Setting::count())->toBe(0)
        ->and(HouseholdSettings::cookNames())->toBe(['me' => 'Me', 'partner' => 'Partner'])
        ->and(HouseholdSettings::timeZone())->toBe('America/Toronto')
        ->and(HouseholdSettings::dinnerTime())->toBe('18:00')
        ->and(HouseholdSettings::planPostTime())->toBe('19:00')
        ->and(HouseholdSettings::dailyDinnerReminderTime())->toBe('15:00')
        ->and(HouseholdSettings::rawSplitFridgeLifeDays())->toBe(2)
        ->and(HouseholdSettings::lowFreezerStashPortions())->toBe(2);
});

it('reads the two shopping windows as weekdays and times', function () {
    $windows = HouseholdSettings::shoppingTimes();

    // Sunday 10:00 am and Wednesday 5:30 pm.
    expect($windows)->toHaveCount(2)
        ->and($windows[0]['weekday'])->toBe(Weekday::Sunday)
        ->and($windows[0]['time'])->toBe('10:00')
        ->and($windows[1]['weekday'])->toBe(Weekday::Wednesday)
        ->and($windows[1]['time'])->toBe('17:30');
});

it('reads the prep block as a weekday and a span', function () {
    // Monday 8:00-9:00 pm.
    expect(HouseholdSettings::prepBlock())->toBe([
        'weekday' => Weekday::Monday,
        'start' => '20:00',
        'end' => '21:00',
    ]);
});

it('lets the household change a shopping day', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_SHOPPING_TIMES, [
        ['weekday' => 'saturday', 'time' => '09:00'],
    ]);

    $windows = HouseholdSettings::shoppingTimes();

    expect($windows)->toHaveCount(1)
        ->and($windows[0]['weekday'])->toBe(Weekday::Saturday);
});

it('calls the cooks by the household’s own names', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, ['me' => 'Adrian', 'partner' => 'Milly']);

    expect(HouseholdSettings::cookLabel(CookAssignment::Me))->toBe('Adrian')
        ->and(HouseholdSettings::cookLabel(CookAssignment::Partner))->toBe('Milly')
        // "Together" and "nobody" are not people, so they keep the enum's label.
        ->and(HouseholdSettings::cookLabel(CookAssignment::Together))->toBe('Together')
        ->and(HouseholdSettings::cookLabel(CookAssignment::Nobody))->toBe('Nobody');
});

it('falls back to the placeholder when a cook has no name', function () {
    HouseholdSettings::set(HouseholdSettings::KEY_COOK_NAMES, ['me' => '  ', 'partner' => 'Milly']);

    expect(HouseholdSettings::cookLabel(CookAssignment::Me))->toBe('Me')
        ->and(HouseholdSettings::cookLabel(CookAssignment::Partner))->toBe('Milly');
});

it('turns a stored time into an instant in the household time zone', function () {
    // Midnight UTC on the 14th: in Toronto that instant is the evening of the
    // 13th, so a helper that converted instead of reading the date would announce
    // dinner a day early. Dates on a plan are stored exactly like this.
    $sixPm = HouseholdSettings::at('18:00', Carbon::parse('2026-01-14 00:00', 'UTC'));

    // 6pm in the kitchen on the 14th, which is 11pm UTC in January.
    expect($sixPm->format('H:i'))->toBe('18:00')
        ->and($sixPm->tzName)->toBe('America/Toronto')
        ->and($sixPm->utc()->format('Y-m-d H:i'))->toBe('2026-01-14 23:00');
});

it('applies the offset in force on the date, not a fixed one', function () {
    // Toronto is UTC-5 in January and UTC-4 in July. A reminder set for 3pm has
    // to be 3pm in the kitchen in both.
    $winter = HouseholdSettings::at('15:00', Carbon::parse('2026-01-14'));
    $summer = HouseholdSettings::at('15:00', Carbon::parse('2026-07-14'));

    expect($winter->utc()->format('H:i'))->toBe('20:00')
        ->and($summer->utc()->format('H:i'))->toBe('19:00');
});

it('reads the date it is given, whatever time zone it carries', function () {
    $fromDateCast = HouseholdSettings::at('18:00', Carbon::parse('2026-01-14', 'UTC'));
    $fromLocal = HouseholdSettings::at('18:00', Carbon::parse('2026-01-14 09:30', 'Europe/Berlin'));

    expect($fromDateCast->eq($fromLocal))->toBeTrue();
});
