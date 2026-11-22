<?php

use App\Enums\Weekday;
use App\Planning\CalendarDate;
use App\Planning\ShoppingCalendar;
use App\Planning\ShoppingWindow;
use Carbon\CarbonImmutable;

/*
 * The shopping-window maths: "a shopping day covers every night from the next day
 * through the next shopping day" (docs/decisions.md). With the default Sunday and
 * Wednesday shops that is Mon-Wed and Thu-Sun.
 *
 * Every date here is in January 2026, where the 11th is a Sunday and the 14th a
 * Wednesday. Nothing in the calendar knows the names of the days, so the last
 * tests move the shops to other weekdays and expect the windows to follow.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

afterEach(fn () => CarbonImmutable::setTestNow());

function windowDates(ShoppingWindow $window): array
{
    return array_map(fn (CarbonImmutable $night) => $night->toDateString(), $window->nights());
}

it('covers Monday to Wednesday from a Sunday shop', function () {
    $window = aShoppingCalendar()->windowFor(CalendarDate::parse('2026-01-11'));

    expect($window->shoppingDate->toDateString())->toBe('2026-01-11')
        ->and($window->firstNight->toDateString())->toBe('2026-01-12')
        ->and($window->lastNight->toDateString())->toBe('2026-01-14')
        ->and($window->nightCount())->toBe(3)
        ->and(windowDates($window))->toBe(['2026-01-12', '2026-01-13', '2026-01-14']);
});

it('covers Thursday to Sunday from a Wednesday shop', function () {
    $window = aShoppingCalendar()->windowFor(CalendarDate::parse('2026-01-14'));

    expect($window->nightCount())->toBe(4)
        ->and(windowDates($window))->toBe(['2026-01-15', '2026-01-16', '2026-01-17', '2026-01-18']);
});

it('covers the whole week when the household shops once', function () {
    $window = aShoppingCalendar(Weekday::Sunday)->windowFor(CalendarDate::parse('2026-01-11'));

    expect($window->nightCount())->toBe(7)
        ->and($window->firstNight->toDateString())->toBe('2026-01-12')
        ->and($window->lastNight->toDateString())->toBe('2026-01-18');
});

it('makes the windows shorter when the household shops more often', function () {
    $calendar = aShoppingCalendar(Weekday::Sunday, Weekday::Tuesday, Weekday::Thursday, Weekday::Friday);

    expect($calendar->windowFor(CalendarDate::parse('2026-01-11'))->nightCount())->toBe(2)
        ->and($calendar->windowFor(CalendarDate::parse('2026-01-15'))->nightCount())->toBe(1)
        ->and($calendar->windowFor(CalendarDate::parse('2026-01-16'))->nightCount())->toBe(2);
});

it('counts the nights of an unplanned mid-week shop too', function () {
    // Shopping on the Monday, with the usual Sunday and Wednesday days set: the
    // shop still has to feed the nights up to the next one.
    $window = aShoppingCalendar()->windowFor(CalendarDate::parse('2026-01-12'));

    expect(windowDates($window))->toBe(['2026-01-13', '2026-01-14']);
});

it('refuses to work without a shopping day', function () {
    expect(fn () => new ShoppingCalendar([]))
        ->toThrow(InvalidArgumentException::class, 'at least one shopping day');
});

it('treats the same weekday listed twice as one shopping day', function () {
    $calendar = new ShoppingCalendar([Weekday::Sunday, Weekday::Sunday, Weekday::Wednesday]);

    expect($calendar->shoppingDays())->toBe([Weekday::Wednesday, Weekday::Sunday])
        ->and($calendar->windowFor(CalendarDate::parse('2026-01-11'))->nightCount())->toBe(3);
});

it('reads the shopping days out of the settings shape', function () {
    // The shape HouseholdSettings::shoppingTimes() returns; the times are not part
    // of the window maths.
    $calendar = ShoppingCalendar::fromShoppingTimes([
        ['weekday' => Weekday::Sunday, 'time' => '10:00'],
        ['weekday' => Weekday::Wednesday, 'time' => '17:30'],
    ]);

    expect(windowDates($calendar->windowFor(CalendarDate::parse('2026-01-11'))))
        ->toBe(['2026-01-12', '2026-01-13', '2026-01-14']);
});

it('finds the next shopping day after a date, never the date itself', function () {
    $calendar = aShoppingCalendar();

    expect($calendar->nextShoppingDateAfter(CalendarDate::parse('2026-01-11'))->toDateString())
        ->toBe('2026-01-14')
        ->and($calendar->nextShoppingDateAfter(CalendarDate::parse('2026-01-12'))->toDateString())
        ->toBe('2026-01-14')
        ->and($calendar->nextShoppingDateAfter(CalendarDate::parse('2026-01-14'))->toDateString())
        ->toBe('2026-01-18');
});

it('counts today as the next shopping day when today is one', function () {
    $calendar = aShoppingCalendar();

    expect($calendar->shoppingDateOnOrAfter(CalendarDate::parse('2026-01-11'))->toDateString())
        ->toBe('2026-01-11')
        ->and($calendar->shoppingDateOnOrAfter(CalendarDate::parse('2026-01-12'))->toDateString())
        ->toBe('2026-01-14')
        ->and($calendar->isShoppingDay(CalendarDate::parse('2026-01-11')))->toBeTrue()
        ->and($calendar->isShoppingDay(CalendarDate::parse('2026-01-13')))->toBeFalse();
});

it('finds the shop a given night was bought for', function (string $night, string $shoppingDate) {
    expect(aShoppingCalendar()->windowCovering(CalendarDate::parse($night))->shoppingDate->toDateString())
        ->toBe($shoppingDate);
})->with([
    // Monday to Wednesday come from Sunday's shop...
    ['2026-01-12', '2026-01-11'],
    ['2026-01-14', '2026-01-11'],
    // ...and Thursday to Sunday from Wednesday's, including Sunday itself.
    ['2026-01-15', '2026-01-14'],
    ['2026-01-18', '2026-01-14'],
]);

it('plans the window the next shop is for', function (string $today, string $firstNight, string $lastNight) {
    $window = aShoppingCalendar()->nextWindow(CalendarDate::parse($today));

    expect($window->firstNight->toDateString())->toBe($firstNight)
        ->and($window->lastNight->toDateString())->toBe($lastNight);
})->with([
    // Saturday evening, when the plan post goes out for Sunday's shop.
    'Saturday' => ['2026-01-17', '2026-01-19', '2026-01-21'],
    // Sunday: shopping today, for Monday to Wednesday.
    'Sunday' => ['2026-01-11', '2026-01-12', '2026-01-14'],
    // Monday: Mon-Wed is already bought for, so the next shop is Wednesday's.
    'Monday' => ['2026-01-12', '2026-01-15', '2026-01-18'],
    'Wednesday' => ['2026-01-14', '2026-01-15', '2026-01-18'],
    // Thursday: bought for on Wednesday, so the next window is next Mon-Wed.
    'Thursday' => ['2026-01-15', '2026-01-19', '2026-01-21'],
]);

it('knows which window tonight belongs to, which is not the same question', function () {
    $calendar = aShoppingCalendar();

    // On a Monday the household is cooking Mon-Wed while the next window to plan
    // is Thu-Sun.
    expect($calendar->currentWindow(CalendarDate::parse('2026-01-12'))->lastNight->toDateString())
        ->toBe('2026-01-14')
        ->and($calendar->nextWindow(CalendarDate::parse('2026-01-12'))->lastNight->toDateString())
        ->toBe('2026-01-18')
        // And on a shopping day, tonight is still the previous window's last night.
        ->and($calendar->currentWindow(CalendarDate::parse('2026-01-11'))->lastNight->toDateString())
        ->toBe('2026-01-11');
});

it('follows the household when it moves its shopping days', function () {
    // Nothing in here knows that Sunday is special: change the setting and the
    // windows move with it.
    $calendar = aShoppingCalendar(Weekday::Tuesday, Weekday::Friday);

    expect(windowDates($calendar->windowFor(CalendarDate::parse('2026-01-13'))))
        ->toBe(['2026-01-14', '2026-01-15', '2026-01-16'])
        ->and(windowDates($calendar->windowFor(CalendarDate::parse('2026-01-16'))))
        ->toBe(['2026-01-17', '2026-01-18', '2026-01-19', '2026-01-20']);
});

it('reads a late-evening instant as that evening, not the next day', function () {
    // 9pm on the Saturday in Toronto is 02:00 Sunday in UTC. Asked with the
    // household's own clock, the next window is still the one Sunday's shop covers.
    $saturdayEvening = CarbonImmutable::parse('2026-01-17 21:00:00', 'America/Toronto');

    $window = aShoppingCalendar()->nextWindow(
        CalendarDate::in($saturdayEvening, 'America/Toronto')
    );

    expect($window->shoppingDate->toDateString())->toBe('2026-01-18')
        ->and(windowDates($window))->toBe(['2026-01-19', '2026-01-20', '2026-01-21']);
});

it('labels a window the way a plan reads it', function () {
    $window = aShoppingCalendar()->windowFor(CalendarDate::parse('2026-01-11'));

    expect($window->label())->toBe('Mon 12 Jan to Wed 14 Jan')
        ->and($window->shoppingWeekday())->toBe(Weekday::Sunday)
        ->and($window->covers(CalendarDate::parse('2026-01-13')))->toBeTrue()
        ->and($window->covers(CalendarDate::parse('2026-01-15')))->toBeFalse()
        ->and($window->covers(CalendarDate::parse('2026-01-11')))->toBeFalse();
});
