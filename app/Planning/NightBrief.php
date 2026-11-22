<?php

namespace App\Planning;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What the picker is given about one night: the date, who is cooking, and how
 * long they have.
 *
 * This is the weekly cook schedule with that date's one-off override already
 * applied — the two inputs the ticket names, collapsed into the single question
 * the picker answers. It carries plain values only, so the rules can be tested
 * without a plan row behind them.
 */
final class NightBrief
{
    public readonly CarbonImmutable $date;

    public function __construct(
        DateTimeInterface $date,
        public readonly CookAssignment $cook,
        public readonly EffortTier $timeBudget,
        public readonly bool $overridesSchedule = false,
        public readonly ?string $note = null,
    ) {
        $this->date = CalendarDate::of($date);
    }

    public function weekday(): Weekday
    {
        return Weekday::fromDate($this->date);
    }

    /**
     * Is anybody cooking? A nobody night gets no recipe: it is leftovers, travel
     * or eating out.
     */
    public function cooks(): bool
    {
        return $this->cook->cooks();
    }

    /**
     * Is my partner cooking on her own?
     *
     * The night the stricter rule applies to: only her 15-minute recipes or a
     * freezer meal (docs/decisions.md). "Together" is not solo — I am there too.
     */
    public function isPartnerSolo(): bool
    {
        return $this->cook === CookAssignment::Partner;
    }

    /**
     * This night with a one-off override applied.
     *
     * Every argument is optional: an override may change who cooks, how long they
     * have, both, or neither — a note on its own ("kids are at their grandparents")
     * is still a departure from the normal week, so it still marks the night.
     */
    public function overriddenBy(
        ?CookAssignment $cook = null,
        ?EffortTier $timeBudget = null,
        ?string $note = null,
    ): self {
        if ($cook === null && $timeBudget === null && $note === null) {
            return $this;
        }

        return new self(
            date: $this->date,
            cook: $cook ?? $this->cook,
            timeBudget: $timeBudget ?? $this->timeBudget,
            overridesSchedule: true,
            note: $note ?? $this->note,
        );
    }

    /**
     * The same night with less time available, for a rule that narrows the budget.
     */
    public function withTimeBudget(EffortTier $timeBudget): self
    {
        if ($timeBudget === $this->timeBudget) {
            return $this;
        }

        return new self(
            date: $this->date,
            cook: $this->cook,
            timeBudget: $timeBudget,
            overridesSchedule: $this->overridesSchedule,
            note: $this->note,
        );
    }

    /**
     * How the night reads in a plan: "Mon 12 Jan".
     */
    public function label(): string
    {
        return $this->date->format('D j M');
    }
}
