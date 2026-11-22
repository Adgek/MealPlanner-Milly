<?php

namespace App\Planning;

/**
 * Dinner for every night of a shopping window.
 *
 * The window is where the rules that span nights live: a meal is used once, a
 * freezer portion is spoken for once, and a night the household has locked keeps
 * what it has while still counting against the rest.
 *
 * Nights are picked in date order, so the plan reads the way it is cooked and the
 * same seed always produces the same window.
 */
final class WindowPicker
{
    public function __construct(private readonly NightPicker $nights) {}

    public static function fromSettings(): self
    {
        return new self(NightPicker::fromSettings());
    }

    public function nightPicker(): NightPicker
    {
        return $this->nights;
    }

    /**
     * Pick for each night given, keeping the picks the household already owns.
     *
     * @param  array<int, NightBrief>  $briefs  the nights to pick for, in date order
     * @param  array<int, Pick>  $keep  picks that stay as they are — locked nights, and
     *                                  on a first roll any night already planned. Their
     *                                  meals are counted, so nothing repeats, but their
     *                                  freezer portions are left alone: those were taken
     *                                  out of the stash when the night was planned.
     * @return array<int, Pick> one per night given, in date order
     */
    public function pick(array $briefs, MealPool $pool, SeededRandom $random, array $keep = []): array
    {
        foreach ($keep as $kept) {
            if (($key = $kept->mealKey()) !== null) {
                $pool->markUsed($key);
            }
        }

        $picks = [];

        foreach ($this->inDateOrder($briefs) as $brief) {
            $picks[] = $this->nights->pick($brief, $pool, $random);
        }

        return $picks;
    }

    /**
     * @param  array<int, NightBrief>  $briefs
     * @return array<int, NightBrief>
     */
    private function inDateOrder(array $briefs): array
    {
        usort($briefs, fn (NightBrief $a, NightBrief $b) => $a->date <=> $b->date);

        return $briefs;
    }
}
