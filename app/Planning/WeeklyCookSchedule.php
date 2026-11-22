<?php

namespace App\Planning;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\CookScheduleNight;
use App\Planning\Exceptions\PlanningException;
use DateTimeInterface;

/**
 * The household's normal week: who cooks on each weekday, and how long they have.
 *
 * Plain values, so the per-night lookup can be tested without the seven rows in
 * cook_schedule_nights behind it. All seven weekdays must be present: the
 * planner has no business inventing who cooks on a Tuesday, and a missing row is
 * a broken schedule rather than a night with no cook (HouseholdDefaultsSeeder
 * installs all seven, and the Settings page edits them).
 */
final class WeeklyCookSchedule
{
    /**
     * @var array<string, array{cook: CookAssignment, timeBudget: EffortTier}>
     */
    private array $week;

    /**
     * @param  array<int, array{weekday: Weekday, cook: CookAssignment, timeBudget: EffortTier}>  $nights
     */
    public function __construct(array $nights)
    {
        $week = [];

        foreach ($nights as $night) {
            $week[$night['weekday']->value] = [
                'cook' => $night['cook'],
                'timeBudget' => $night['timeBudget'],
            ];
        }

        foreach (Weekday::week() as $weekday) {
            if (! isset($week[$weekday->value])) {
                throw PlanningException::scheduleMissingWeekday($weekday);
            }
        }

        $this->week = $week;
    }

    /**
     * The weekly schedule as Settings holds it.
     */
    public static function fromSettings(): self
    {
        return new self(CookScheduleNight::week()
            ->map(fn (CookScheduleNight $night) => [
                'weekday' => $night->weekday,
                'cook' => $night->cook,
                'timeBudget' => $night->time_budget,
            ])
            ->values()
            ->all());
    }

    public function cookOn(Weekday $weekday): CookAssignment
    {
        return $this->week[$weekday->value]['cook'];
    }

    public function timeBudgetOn(Weekday $weekday): EffortTier
    {
        return $this->week[$weekday->value]['timeBudget'];
    }

    /**
     * The brief for one date, before any one-off override for that date.
     */
    public function nightOn(DateTimeInterface $date): NightBrief
    {
        $weekday = Weekday::fromDate(CalendarDate::of($date));

        return new NightBrief(
            date: $date,
            cook: $this->cookOn($weekday),
            timeBudget: $this->timeBudgetOn($weekday),
        );
    }

    /**
     * The briefs for every night of a window, in order.
     *
     * @return array<int, NightBrief>
     */
    public function nightsOf(ShoppingWindow $window): array
    {
        return array_map(fn ($night) => $this->nightOn($night), $window->nights());
    }
}
