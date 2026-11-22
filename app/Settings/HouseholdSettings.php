<?php

namespace App\Settings;

use App\Enums\CookAssignment;
use App\Enums\Weekday;
use App\Models\Setting;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Typed access to the household's settings, with the starting defaults from
 * docs/decisions.md.
 *
 * Every household value belongs here rather than in a constant (AGENTS.md), and
 * every reader goes through this class rather than Setting::read, so that the
 * default for a setting lives in exactly one place and a time comes back as a
 * time instead of the string "18:00".
 *
 * The defaults are what a fresh database starts with (SettingsSeeder writes them
 * as real rows) and also what these methods return if a row is ever missing, so
 * a half-seeded database still behaves.
 */
class HouseholdSettings
{
    public const KEY_COOK_NAMES = 'cook_names';

    public const KEY_TIME_ZONE = 'time_zone';

    public const KEY_SHOPPING_TIMES = 'shopping_times';

    public const KEY_PREP_BLOCK = 'prep_block';

    public const KEY_DINNER_TIME = 'dinner_time';

    public const KEY_PLAN_POST_TIME = 'plan_post_time';

    public const KEY_DAILY_DINNER_REMINDER_TIME = 'daily_dinner_reminder_time';

    public const KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS = 'raw_split_fridge_life_days';

    public const KEY_LOW_FREEZER_STASH_PORTIONS = 'low_freezer_stash_portions';

    /**
     * The starting settings from docs/decisions.md, with their defaults.
     *
     * The weekly cook schedule is deliberately not here: it is seven rows in
     * cook_schedule_nights, seeded by CookScheduleSeeder.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // What the two cooks are called, keyed by the CookAssignment they
            // answer to. Placeholders until the household puts its own names in
            // (MM-4), because "Partner is cooking" reads fine and a wrong name
            // does not.
            self::KEY_COOK_NAMES => [
                CookAssignment::Me->value => 'Me',
                CookAssignment::Partner->value => 'Partner',
            ],

            self::KEY_TIME_ZONE => 'America/Toronto',

            // Sunday 10:00 am and Wednesday 5:30 pm. A shopping day covers every
            // night from the next day through the next shopping day, so this list
            // is what the plan's windows are derived from (MM-6).
            self::KEY_SHOPPING_TIMES => [
                ['weekday' => Weekday::Sunday->value, 'time' => '10:00'],
                ['weekday' => Weekday::Wednesday->value, 'time' => '17:30'],
            ],

            // Monday 8:00-9:00 pm.
            self::KEY_PREP_BLOCK => [
                'weekday' => Weekday::Monday->value,
                'start' => '20:00',
                'end' => '21:00',
            ],

            self::KEY_DINNER_TIME => '18:00',

            // 7:00 pm the evening before each shopping day. Only the time is
            // stored; which evening is derived from the shopping days.
            self::KEY_PLAN_POST_TIME => '19:00',

            self::KEY_DAILY_DINNER_REMINDER_TIME => '15:00',

            // How long the raw second half of a bulk pack keeps, for a bulk
            // ingredient that doesn't record its own fridge life.
            self::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS => 2,

            self::KEY_LOW_FREEZER_STASH_PORTIONS => 2,
        ];
    }

    public static function get(string $key): mixed
    {
        return Setting::read($key, self::defaults()[$key] ?? null);
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::write($key, $value);
    }

    /**
     * The household's time zone: the one every displayed time is rendered in,
     * and the one the scheduler fires on.
     */
    public static function timeZone(): string
    {
        return (string) self::get(self::KEY_TIME_ZONE);
    }

    /**
     * The two cooks' names, keyed by CookAssignment value.
     *
     * @return array{me: string, partner: string}
     */
    public static function cookNames(): array
    {
        $names = (array) self::get(self::KEY_COOK_NAMES);
        $defaults = self::defaults()[self::KEY_COOK_NAMES];

        return [
            CookAssignment::Me->value => trim((string) ($names[CookAssignment::Me->value] ?? '')) ?:
                $defaults[CookAssignment::Me->value],
            CookAssignment::Partner->value => trim((string) ($names[CookAssignment::Partner->value] ?? '')) ?:
                $defaults[CookAssignment::Partner->value],
        ];
    }

    /**
     * What to call a night's cook on a page or in a Slack post: the household's
     * own name for the two people, and the enum's label for "together" and
     * "nobody", which are not people.
     *
     * Every reader that shows a cook to a human goes through here, so renaming a
     * cook in Settings renames them everywhere.
     */
    public static function cookLabel(CookAssignment $cook): string
    {
        return self::cookNames()[$cook->value] ?? $cook->label();
    }

    /**
     * When dinner is, as "HH:MM".
     */
    public static function dinnerTime(): string
    {
        return (string) self::get(self::KEY_DINNER_TIME);
    }

    /**
     * When the plan goes out, the evening before a shopping day, as "HH:MM".
     */
    public static function planPostTime(): string
    {
        return (string) self::get(self::KEY_PLAN_POST_TIME);
    }

    /**
     * When the daily "here's tonight's dinner" reminder goes out, as "HH:MM".
     */
    public static function dailyDinnerReminderTime(): string
    {
        return (string) self::get(self::KEY_DAILY_DINNER_REMINDER_TIME);
    }

    /**
     * The shopping windows, as a list of ['weekday' => Weekday, 'time' => 'HH:MM'].
     *
     * @return array<int, array{weekday: Weekday, time: string}>
     */
    public static function shoppingTimes(): array
    {
        $times = (array) self::get(self::KEY_SHOPPING_TIMES);

        return array_values(array_map(fn (array $window) => [
            'weekday' => Weekday::from($window['weekday']),
            'time' => (string) $window['time'],
        ], $times));
    }

    /**
     * The weekly prep block, as ['weekday' => Weekday, 'start' => 'HH:MM', 'end' => 'HH:MM'].
     *
     * @return array{weekday: Weekday, start: string, end: string}
     */
    public static function prepBlock(): array
    {
        $block = (array) self::get(self::KEY_PREP_BLOCK);

        return [
            'weekday' => Weekday::from($block['weekday']),
            'start' => (string) $block['start'],
            'end' => (string) $block['end'],
        ];
    }

    /**
     * The fallback fridge life for a raw split, in days.
     */
    public static function rawSplitFridgeLifeDays(): int
    {
        return (int) self::get(self::KEY_RAW_SPLIT_FRIDGE_LIFE_DAYS);
    }

    /**
     * The portion count at or below which the freezer stash counts as low (MM-12).
     */
    public static function lowFreezerStashPortions(): int
    {
        return (int) self::get(self::KEY_LOW_FREEZER_STASH_PORTIONS);
    }

    /**
     * Turn one of the stored "HH:MM" settings into an instant on a given date,
     * in the household time zone.
     *
     * This is the bridge between a stored wall-clock time and an absolute moment:
     * reminders and plan posts need "6:00 pm in the kitchen on this date", which
     * is a different instant in summer than in winter. The result is a Carbon in
     * the household zone — call ->utc() before storing it.
     *
     * $date is read as a calendar date: only its year, month and day are used,
     * and any time or time zone on it is ignored. That is deliberate. Converting
     * the instant instead would move the date — midnight UTC on the 14th is the
     * evening of the 13th in Toronto — and dinner would be announced a day early.
     */
    public static function at(string $time, DateTimeInterface $date): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '0');

        return Carbon::create(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j'),
            (int) $hour,
            (int) $minute,
            0,
            self::timeZone(),
        );
    }
}
