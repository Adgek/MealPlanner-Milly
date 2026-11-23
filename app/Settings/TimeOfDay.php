<?php

namespace App\Settings;

/**
 * A wall-clock time of day, the way the settings store it: "HH:MM" on a
 * 24-hour clock.
 *
 * Every time on the Settings page passes through here before it is validated or
 * saved, so the settings table only ever holds one shape. That matters because
 * the readers downstream are unforgiving: HouseholdSettings::at() splits on ":"
 * and casts, and the scheduler is handed the string as-is. A stored "9:00",
 * "09:00:00" or " 09:00 " would each be read differently, and a reminder that
 * fires an hour out is exactly the kind of bug nobody notices until dinner.
 *
 * Plain values in, plain values out: no Carbon, no time zone, no database. A
 * time of day is not an instant — turning one into a moment needs a date and
 * the household's zone, which is HouseholdSettings::at()'s job.
 */
final class TimeOfDay
{
    /**
     * The stored format, and the one `date_format` validates against.
     */
    public const FORMAT = 'H:i';

    /**
     * Tidy a typed time into "HH:MM", or null when it is not a time at all.
     *
     * Accepts what the browsers and the household actually produce: "9:00" from
     * a typed entry, "09:00:00" from a time input with seconds enabled, and
     * stray whitespace from a paste. Anything else — "25:00", "7pm", "" — comes
     * back null, and the caller keeps the raw input so the validation message
     * can quote what was typed.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', $value, $parts)) {
            return null;
        }

        $hour = (int) $parts[1];

        if ($hour > 23) {
            return null;
        }

        return sprintf('%02d:%s', $hour, $parts[2]);
    }

    /**
     * Is this a time of day the settings can store?
     */
    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * Minutes since midnight, for comparing two times on the same day — the
     * prep block ending after it starts. Null when the value is not a time.
     */
    public static function minutes(?string $value): ?int
    {
        $time = self::normalize($value);

        if ($time === null) {
            return null;
        }

        [$hour, $minute] = explode(':', $time);

        return ((int) $hour * 60) + (int) $minute;
    }

    /**
     * The same time written the way the household says it: "18:00" is
     * "6:00 pm", "00:05" is "12:05 am", "12:00" is "12:00 pm".
     *
     * Used for the summary lines on the Settings page. An unparseable value is
     * handed back untouched rather than hidden, so a bad row in the database is
     * visible instead of silently rendering as midnight.
     */
    public static function label(?string $value): string
    {
        $time = self::normalize($value);

        if ($time === null) {
            return trim((string) $value);
        }

        [$hour, $minute] = array_map('intval', explode(':', $time));

        $meridiem = $hour < 12 ? 'am' : 'pm';
        $twelve = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%d:%02d %s', $twelve, $minute, $meridiem);
    }
}
