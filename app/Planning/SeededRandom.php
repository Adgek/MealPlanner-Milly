<?php

namespace App\Planning;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The randomizer's source of chance, with a seed so a roll can be repeated.
 *
 * Every random choice the picker makes comes from here. Given the same seed and
 * the same vault, a plan comes out identical — which is what makes the rules
 * testable, and what lets a plan be explained after the fact ("seed 12345 picked
 * this"). Mt19937 is used rather than the platform default because its sequence
 * is fixed by the algorithm, so a seeded test stays valid across PHP versions.
 */
final class SeededRandom
{
    private Randomizer $randomizer;

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($this->seed));
    }

    /**
     * A generator for the given seed, or a fresh random one when there is none.
     *
     * The seed it ended up using is readable afterwards, so an unseeded plan can
     * still be reproduced later.
     */
    public static function fromSeed(?int $seed = null): self
    {
        return new self($seed ?? random_int(1, PHP_INT_MAX));
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * Pick one index, with the chance of each proportional to its weight.
     *
     * This is how "favour meals that haven't been cooked recently" stays random
     * rather than becoming an order: a stale meal is more likely, not certain.
     * All-zero weights fall back to an even chance, so a pool of meals we cooked
     * today still produces dinner.
     *
     * @param  array<int, int>  $weights
     */
    public function weightedIndex(array $weights): int
    {
        $weights = array_values($weights);
        $total = array_sum($weights);

        if ($total <= 0) {
            return $this->int(0, count($weights) - 1);
        }

        $roll = $this->int(1, $total);

        foreach ($weights as $index => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $index;
            }
        }

        return count($weights) - 1;
    }
}
