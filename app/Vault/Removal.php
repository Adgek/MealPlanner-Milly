<?php

namespace App\Vault;

use Illuminate\Support\Str;

/**
 * What removing a meal actually did, as the household reads it afterwards
 * (MM-15).
 *
 * Deliberately separate from MealUsage, which is the warning *before* the
 * button: that is a forecast, and this is the outcome. The two are not the same
 * thing, and saying so in one class made the banner lie — a re-pick that finds
 * nothing else fitting keeps the meal on the night it was taken off
 * (MealPlanner::rerollNight(), MM-6), so a sentence written from "these nights
 * will be picked again" claimed a dinner had changed while the plan still read
 * the same.
 *
 * So Retirement compares each night before and after the re-pick and hands the
 * two lists over. Plain values, so the wording is unit-tested with no database
 * (AGENTS.md).
 */
final class Removal
{
    /**
     * @param  array<int, UsedNight>  $changed  nights that really got another dinner
     * @param  array<int, UsedNight>  $kept  nights the re-pick could not change — nothing else fitted
     * @param  array<int, UsedNight>  $locked  nights left alone, because the household locked them
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $fromTheStash = false,
        public readonly array $changed = [],
        public readonly array $kept = [],
        public readonly array $locked = [],
        public readonly int $portionsHandedBack = 0,
        public readonly bool $hadBeenEaten = false,
    ) {}

    /**
     * The banner: what happened, in the order it matters.
     */
    public function describe(): string
    {
        return implode(' ', array_values(array_filter([
            $this->whatLeft(),
            $this->aboutChangedNights(),
            $this->aboutKeptNights(),
            $this->aboutLockedNights(),
            $this->aboutPortions(),
            $this->hadBeenEaten ? 'Nothing you have cooked was lost.' : null,
        ])));
    }

    private function whatLeft(): string
    {
        return $this->fromTheStash
            ? $this->name.' is out of the stash, and the picker will not plan it again.'
            : $this->name.' is out of the vault, and the picker will not choose it again.';
    }

    private function aboutChangedNights(): ?string
    {
        if ($this->changed === []) {
            return null;
        }

        return sprintf(
            '%s now %s.',
            self::datesOf($this->changed),
            count($this->changed) === 1 ? 'has a different dinner' : 'have different dinners',
        );
    }

    /**
     * A night that kept the meal, because the rules ruled everything else out.
     *
     * Worth saying out loud and worth acting on: the shop for that window was
     * planned around this meal, so MM-6 keeps it rather than leaving the night
     * empty — but the household has just said they do not want it, and the way
     * out is to give the picker something that fits or to pick the night by hand.
     */
    private function aboutKeptNights(): ?string
    {
        if ($this->kept === []) {
            return null;
        }

        return sprintf(
            '%s kept it: nothing else in the %s fits %s. Add a %s that fits, or pick the dinner '
            .'yourself on the plan page.',
            self::datesOf($this->kept),
            $this->fromTheStash ? 'stash' : 'vault',
            count($this->kept) === 1 ? 'that night' : 'those nights',
            $this->fromTheStash ? 'freezer meal' : 'recipe',
        );
    }

    private function aboutLockedNights(): ?string
    {
        if ($this->locked === []) {
            return null;
        }

        return sprintf(
            '%s %s it: %s locked.',
            self::datesOf($this->locked),
            count($this->locked) === 1 ? 'keeps' : 'keep',
            count($this->locked) === 1 ? 'that night is' : 'those nights are',
        );
    }

    private function aboutPortions(): ?string
    {
        if ($this->portionsHandedBack < 1) {
            return null;
        }

        return sprintf(
            '%d %s back into the count.',
            $this->portionsHandedBack,
            $this->portionsHandedBack === 1 ? 'portion went' : 'portions went',
        );
    }

    /**
     * @param  array<int, UsedNight>  $nights
     */
    private static function datesOf(array $nights, int $cap = 3): string
    {
        $labels = array_map(fn (UsedNight $night): string => $night->label(), $nights);
        $extra = count($labels) - $cap;

        if ($extra > 0) {
            $labels = array_slice($labels, 0, $cap);
            $labels[] = $extra.' other '.Str::plural('night', $extra);
        }

        if (count($labels) < 2) {
            return (string) ($labels[0] ?? '');
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }
}
