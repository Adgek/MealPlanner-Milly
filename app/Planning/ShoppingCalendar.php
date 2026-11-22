<?php

namespace App\Planning;

use App\Enums\Weekday;
use App\Settings\HouseholdSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The shopping-window maths: which nights a shop has to cover, and which window
 * the household is cooking into.
 *
 * Built from the household's shopping days, so there is no day-name condition
 * anywhere in here — change the setting to Tuesday and Friday and the windows
 * follow. The *times* of the shops are deliberately not used: a window is a span
 * of calendar dates, and the hour the household walks into the shop never moves
 * which nights the shop has to feed. MM-9 needs the time, for when to post; this
 * does not.
 */
final class ShoppingCalendar
{
    /**
     * ISO day numbers (Monday 1 ... Sunday 7), ascending and unique.
     *
     * @var array<int, int>
     */
    private array $isoDays;

    /**
     * @param  array<int, Weekday>  $shoppingDays
     */
    public function __construct(array $shoppingDays)
    {
        $isoDays = array_values(array_unique(array_map(
            fn (Weekday $day) => $day->isoNumber(),
            $shoppingDays,
        )));

        if ($isoDays === []) {
            throw new InvalidArgumentException(
                'The household needs at least one shopping day: there is no window without one.'
            );
        }

        sort($isoDays);

        $this->isoDays = $isoDays;
    }

    /**
     * The calendar as the household has it in Settings.
     */
    public static function fromSettings(): self
    {
        return self::fromShoppingTimes(HouseholdSettings::shoppingTimes());
    }

    /**
     * Build from the shape HouseholdSettings::shoppingTimes() returns, dropping
     * the times.
     *
     * @param  array<int, array{weekday: Weekday, time: string}>  $shoppingTimes
     */
    public static function fromShoppingTimes(array $shoppingTimes): self
    {
        return new self(array_map(fn (array $shop) => $shop['weekday'], $shoppingTimes));
    }

    /**
     * The shopping days, Monday first.
     *
     * @return array<int, Weekday>
     */
    public function shoppingDays(): array
    {
        return array_map(
            fn (int $iso) => Weekday::week()[$iso - 1],
            $this->isoDays,
        );
    }

    public function isShoppingDay(DateTimeInterface $date): bool
    {
        return in_array($this->isoNumber($date), $this->isoDays, true);
    }

    /**
     * The first shopping day strictly after this date.
     *
     * Strictly: asking on a Sunday gives the following Wednesday, not Sunday
     * itself, because a shop never covers the night of the shop before it.
     */
    public function nextShoppingDateAfter(DateTimeInterface $date): CarbonImmutable
    {
        $today = $this->isoNumber($date);

        $offsets = array_map(
            fn (int $iso) => ($iso - $today + 7) % 7 ?: 7,
            $this->isoDays,
        );

        return CalendarDate::of($date)->addDays(min($offsets));
    }

    /**
     * The first shopping day on or after this date — today, if today is one.
     *
     * This is the shop the household has next, which is the window they are about
     * to plan and buy for.
     */
    public function shoppingDateOnOrAfter(DateTimeInterface $date): CarbonImmutable
    {
        return $this->isShoppingDay($date)
            ? CalendarDate::of($date)
            : $this->nextShoppingDateAfter($date);
    }

    /**
     * The last shopping day strictly before this date.
     */
    public function lastShoppingDateBefore(DateTimeInterface $date): CarbonImmutable
    {
        $today = $this->isoNumber($date);

        $offsets = array_map(
            fn (int $iso) => ($today - $iso + 7) % 7 ?: 7,
            $this->isoDays,
        );

        return CalendarDate::of($date)->subDays(min($offsets));
    }

    /**
     * The window a shop on this date has to cover: the next day through the next
     * shopping day, inclusive.
     *
     * Any date works, not just a shopping day, so an unplanned mid-week shop
     * still produces a sensible window.
     */
    public function windowFor(DateTimeInterface $shoppingDate): ShoppingWindow
    {
        $date = CalendarDate::of($shoppingDate);

        return new ShoppingWindow(
            shoppingDate: $date,
            firstNight: $date->addDay(),
            lastNight: $this->nextShoppingDateAfter($date),
        );
    }

    /**
     * The window a given night belongs to.
     *
     * Nights run from the day after a shop up to and including the next shop, so
     * the window that feeds a night is the one shopped for on the last shopping
     * day before it.
     */
    public function windowCovering(DateTimeInterface $night): ShoppingWindow
    {
        return $this->windowFor($this->lastShoppingDateBefore($night));
    }

    /**
     * The next window to plan: the one the next shop is for.
     *
     * The next shop is today if today is a shopping day, so Sunday morning plans
     * Mon-Wed and so does Saturday evening, when the plan post goes out. Once
     * Sunday's shop is done, Monday through Wednesday is already bought for and
     * the next window to plan is Wednesday's.
     *
     * This is not the same question as "what are we eating tonight": on a Monday
     * this returns Thu-Sun while the household is cooking Mon-Wed. Use
     * currentWindow() for that.
     */
    public function nextWindow(DateTimeInterface $today): ShoppingWindow
    {
        return $this->windowFor($this->shoppingDateOnOrAfter($today));
    }

    /**
     * The window in force: the one tonight's dinner belongs to.
     *
     * On a shopping day this is still the *previous* window — Sunday's dinner is
     * covered by Wednesday's shop, because a shop feeds the nights after it.
     */
    public function currentWindow(DateTimeInterface $today): ShoppingWindow
    {
        return $this->windowCovering($today);
    }

    private function isoNumber(DateTimeInterface $date): int
    {
        return Weekday::fromDate(CalendarDate::of($date))->isoNumber();
    }
}
