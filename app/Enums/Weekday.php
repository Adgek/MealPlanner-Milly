<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * A day of the week, stored by name.
 *
 * Names rather than numbers because these land in the settings table and in the
 * cook schedule, both of which a person reads directly in MySQL when something
 * looks wrong. "sunday" needs no lookup; 0 does, and the 0-means-Sunday and
 * 1-means-Monday conventions disagree.
 */
enum Weekday: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * ISO-8601 day number: Monday is 1, Sunday is 7.
     */
    public function isoNumber(): int
    {
        return match ($this) {
            self::Monday => 1,
            self::Tuesday => 2,
            self::Wednesday => 3,
            self::Thursday => 4,
            self::Friday => 5,
            self::Saturday => 6,
            self::Sunday => 7,
        };
    }

    /**
     * The number Carbon and PHP's date('w') use: Sunday is 0, Saturday is 6.
     */
    public function carbonDayOfWeek(): int
    {
        return $this->isoNumber() % 7;
    }

    /**
     * The weekday a date falls on.
     *
     * The date is read as-is. Callers working with an instant rather than a
     * calendar date should convert it to the household time zone first, because
     * 11pm Toronto is already tomorrow in UTC.
     */
    public static function fromDate(DateTimeInterface $date): self
    {
        $dayOfWeek = $date instanceof CarbonInterface
            ? $date->dayOfWeek
            : (int) $date->format('w');

        return match ($dayOfWeek) {
            0 => self::Sunday,
            1 => self::Monday,
            2 => self::Tuesday,
            3 => self::Wednesday,
            4 => self::Thursday,
            5 => self::Friday,
            6 => self::Saturday,
        };
    }

    /**
     * Every weekday, Monday first.
     *
     * @return array<int, self>
     */
    public static function week(): array
    {
        return self::cases();
    }
}
