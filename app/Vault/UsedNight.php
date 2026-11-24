<?php

namespace App\Vault;

use App\Models\PlanNight;
use App\Planning\CalendarDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A night of a plan that points at the meal somebody is about to remove.
 *
 * Plain values, so the rules about what a removal may do — and the sentences the
 * household reads before pressing the button — can be worked out and tested
 * without a database (AGENTS.md).
 *
 * The three facts that matter are the three the planner itself cares about: the
 * date, whether that date has already gone by (a night that has is history and
 * must not be touched, MM-6), and whether the household locked it.
 */
final class UsedNight
{
    public readonly CarbonImmutable $date;

    public function __construct(
        public readonly int $id,
        DateTimeInterface $date,
        public readonly bool $hasPassed = false,
        public readonly bool $isLocked = false,
        /**
         * Is this night on the plan that is out now, rather than one further
         * ahead? Only used to say so in words.
         */
        public readonly bool $isCurrentPlan = false,
    ) {
        $this->date = CalendarDate::of($date);
    }

    public static function fromPlanNight(PlanNight $night, bool $isCurrentPlan = false): self
    {
        return new self(
            id: (int) $night->getKey(),
            date: $night->date,
            hasPassed: $night->hasPassed(),
            isLocked: $night->is_locked,
            isCurrentPlan: $isCurrentPlan,
        );
    }

    /**
     * Can the planner still change this night's dinner?
     */
    public function canBeRepicked(): bool
    {
        return ! $this->hasPassed && ! $this->isLocked;
    }

    /**
     * The night as a person reads it: "Tue 25 Nov", the same shape the plan and
     * the command print (App\Planning\NightBrief).
     */
    public function label(): string
    {
        return $this->date->format('D j M');
    }
}
