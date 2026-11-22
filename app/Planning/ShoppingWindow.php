<?php

namespace App\Planning;

use App\Enums\Weekday;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One shopping window: the shop, and the nights that shop has to cover.
 *
 * A shopping day covers every night from the next day through the next shopping
 * day (docs/decisions.md). With the default Sunday and Wednesday shops, Sunday
 * covers Mon-Wed and Wednesday covers Thu-Sun.
 *
 * All three dates are calendar dates, never instants: see CalendarDate.
 */
final class ShoppingWindow
{
    public readonly CarbonImmutable $shoppingDate;

    public readonly CarbonImmutable $firstNight;

    public readonly CarbonImmutable $lastNight;

    public function __construct(
        DateTimeInterface $shoppingDate,
        DateTimeInterface $firstNight,
        DateTimeInterface $lastNight,
    ) {
        $this->shoppingDate = CalendarDate::of($shoppingDate);
        $this->firstNight = CalendarDate::of($firstNight);
        $this->lastNight = CalendarDate::of($lastNight);
    }

    /**
     * Every night the shop covers, in order.
     *
     * @return array<int, CarbonImmutable>
     */
    public function nights(): array
    {
        $nights = [];

        for ($night = $this->firstNight; $night->lessThanOrEqualTo($this->lastNight); $night = $night->addDay()) {
            $nights[] = $night;
        }

        return $nights;
    }

    public function nightCount(): int
    {
        return CalendarDate::daysBetween($this->firstNight, $this->lastNight) + 1;
    }

    public function covers(DateTimeInterface $night): bool
    {
        $date = CalendarDate::of($night);

        return $date->greaterThanOrEqualTo($this->firstNight)
            && $date->lessThanOrEqualTo($this->lastNight);
    }

    public function shoppingWeekday(): Weekday
    {
        return Weekday::fromDate($this->shoppingDate);
    }

    public function is(self $other): bool
    {
        return $this->shoppingDate->equalTo($other->shoppingDate)
            && $this->firstNight->equalTo($other->firstNight)
            && $this->lastNight->equalTo($other->lastNight);
    }

    /**
     * How the window reads on a plan or in a Slack post: "Mon 12 Jan to Wed 14 Jan".
     */
    public function label(): string
    {
        return $this->firstNight->format('D j M').' to '.$this->lastNight->format('D j M');
    }
}
