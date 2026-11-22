<?php

namespace App\Planning;

use App\Enums\EffortTier;
use App\Settings\HouseholdSettings;

/**
 * The household's answers to the questions the picker has to ask.
 *
 * Every one of these is a Settings value with a starting default, not a constant
 * (AGENTS.md): how strict my partner's solo nights are, how long a meal counts as
 * recently cooked, and whether the freezer stash is reserved for chaos nights.
 * The picker takes them as plain values so the rules can be tested at any
 * setting, including ones the household hasn't chosen.
 */
final class PlanningRules
{
    public function __construct(
        /**
         * The most effort a recipe may demand when my partner cooks alone.
         */
        public readonly EffortTier $partnerSoloEffortCap,
        /**
         * How many days back a meal counts as recently cooked.
         */
        public readonly int $varietyLookbackDays,
        /**
         * Spend a freezer portion only on a night with 15 minutes in it.
         */
        public readonly bool $freezerMealsOnChaosNightsOnly,
    ) {}

    /**
     * The rules as the household has them in Settings.
     */
    public static function fromSettings(): self
    {
        return new self(
            partnerSoloEffortCap: HouseholdSettings::partnerSoloEffortCap(),
            varietyLookbackDays: HouseholdSettings::varietyLookbackDays(),
            freezerMealsOnChaosNightsOnly: HouseholdSettings::freezerMealsOnChaosNightsOnly(),
        );
    }

    /**
     * How much cooking a night can actually take on.
     *
     * The weekly schedule says how much time there is; a solo night for my
     * partner narrows it, never widens it, so a 60-minute Saturday she is on her
     * own for is still a 15-minute night.
     */
    public function timeAvailableOn(NightBrief $night): EffortTier
    {
        if (! $night->isPartnerSolo()) {
            return $night->timeBudget;
        }

        return $night->timeBudget->minutes() <= $this->partnerSoloEffortCap->minutes()
            ? $night->timeBudget
            : $this->partnerSoloEffortCap;
    }

    /**
     * May a freezer portion be spent on this night?
     */
    public function allowsFreezerMealOn(EffortTier $timeAvailable): bool
    {
        if (! $this->freezerMealsOnChaosNightsOnly) {
            return true;
        }

        return $timeAvailable->minutes() <= EffortTier::Chaos15->minutes();
    }
}
