<?php

namespace App\Enums;

/**
 * How much cooking a thing takes, and how much time a night has for it
 * (AppPlan.md, "Time / Effort Tier").
 *
 * One enum deliberately serves both sides of the match the picker (MM-6) makes:
 *
 *   - on a recipe, it is the effort the recipe demands;
 *   - on a cook-schedule night or a plan night, it is the time available.
 *
 * Sharing the type is what lets "a 30-minute night" and "a 30-minute workhorse"
 * be compared without a translation table. The 60-minute tier means "60 or more",
 * so minutes() is a floor, not a duration.
 */
enum EffortTier: string
{
    case Chaos15 = '15_min_chaos';
    case Workhorse30 = '30_min_workhorse';
    case Project60 = '60_min_project';

    public function label(): string
    {
        return match ($this) {
            self::Chaos15 => '15-min chaos',
            self::Workhorse30 => '30-min workhorse',
            self::Project60 => '60-min+ project',
        };
    }

    /**
     * The least time this tier needs, in minutes. Project60 has no upper bound.
     */
    public function minutes(): int
    {
        return match ($this) {
            self::Chaos15 => 15,
            self::Workhorse30 => 30,
            self::Project60 => 60,
        };
    }
}
