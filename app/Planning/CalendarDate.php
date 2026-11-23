<?php

namespace App\Planning;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Calendar dates, kept free of instants.
 *
 * A night is a calendar date, not a moment (docs/decisions.md): the plan's
 * nights, the shopping dates and meal history are all `date` columns. Every date
 * the planner works with goes through here first, so the window maths can never
 * be handed a timestamp that is "tomorrow" in UTC and "tonight" in the kitchen.
 *
 * Dates are carried as midnight UTC. The zone is an implementation detail and is
 * never displayed — what matters is that two dates built this way are always a
 * whole number of days apart, which is not true of a zone with daylight saving.
 */
final class CalendarDate
{
    /**
     * The calendar date a value already names, ignoring any time and zone on it.
     *
     * Deliberately *not* a conversion: "2026-01-14 23:00 America/Toronto" is the
     * 14th here, and so is "2026-01-14 00:30 UTC". Use in() when the input is an
     * instant and the question is which day it fell on in the kitchen.
     */
    public static function of(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::create(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j'),
            0,
            0,
            0,
            'UTC',
        );
    }

    /**
     * The calendar date an instant falls on in a given time zone.
     *
     * This is the conversion that crosses the day boundary: 03:30 UTC on the 15th
     * is still the evening of the 14th in Toronto, so planning "from now" has to
     * ask the household's clock, not the server's.
     */
    public static function in(DateTimeInterface $instant, string $timeZone): CarbonImmutable
    {
        return self::of(CarbonImmutable::instance($instant)->setTimezone($timeZone));
    }

    /**
     * Today's date in a given time zone.
     */
    public static function today(string $timeZone): CarbonImmutable
    {
        return self::in(CarbonImmutable::now($timeZone), $timeZone);
    }

    /**
     * A "YYYY-MM-DD" string as a calendar date.
     */
    public static function parse(string $date): CarbonImmutable
    {
        return self::of(CarbonImmutable::parse($date));
    }

    /**
     * Is this calendar date behind today, on a given clock?
     *
     * The fence the planner keeps around a night that has gone by: what was on it
     * is what was cooked, so it cannot be re-rolled, swapped, re-dated or have its
     * freezer portion handed back (docs/decisions.md). Today is **not** past —
     * tonight's dinner is still a decision — and the zone is the household's, so
     * 8pm in Toronto is still today even where the server says tomorrow.
     */
    public static function hasPassed(DateTimeInterface $date, string $timeZone): bool
    {
        return self::of($date)->lessThan(self::today($timeZone));
    }

    /**
     * Whole days from one calendar date to another, negative when $to is earlier.
     */
    public static function daysBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        return (int) self::of($from)->diffInDays(self::of($to), false);
    }
}
