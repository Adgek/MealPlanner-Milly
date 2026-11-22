<?php

namespace App\Planning\Exceptions;

use App\Enums\Weekday;
use App\Models\FreezerItem;
use App\Models\PlanNight;
use RuntimeException;

/**
 * The planner refusing to do something that would quietly corrupt a plan.
 *
 * One exception type, with a named constructor per case, so a page or a command
 * can catch "the planner said no" in one place and still show the household a
 * specific reason.
 */
class PlanningException extends RuntimeException
{
    /**
     * A locked night is the household's own answer. Re-rolling it would throw
     * away a decision somebody made on purpose, so the caller has to unlock it
     * first (docs/decisions.md, "Plan review").
     */
    public static function lockedNight(PlanNight $night): self
    {
        return new self(sprintf(
            'The night of %s is locked. Unlock it before re-rolling it.',
            $night->date->toDateString(),
        ));
    }

    /**
     * Planning a freezer meal spends a portion, so there has to be one. Without
     * this the stash count would go negative and MM-12's low-stash warning would
     * be measuring a number that never happened.
     */
    public static function noPortionsLeft(FreezerItem $item): self
    {
        return new self(sprintf(
            'There are no portions of [%s] left to plan a night around.',
            $item->name,
        ));
    }

    /**
     * The weekly cook schedule is seven rows, one per weekday. A missing row is
     * a broken schedule: the planner has no business deciding who cooks instead.
     */
    public static function scheduleMissingWeekday(Weekday $weekday): self
    {
        return new self(sprintf(
            'The weekly cook schedule has no %s. Seed the household defaults, or set the night in Settings.',
            $weekday->label(),
        ));
    }
}
