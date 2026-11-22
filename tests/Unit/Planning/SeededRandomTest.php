<?php

use App\Planning\SeededRandom;

/*
 * "Randomness takes a seed, so tests can be repeated" (MM-6). Everything else in
 * the picker rests on that: without it, none of the rules above could be asserted.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

it('gives the same sequence for the same seed', function () {
    $first = new SeededRandom(2026);
    $second = new SeededRandom(2026);

    $drawn = [];
    $redrawn = [];

    for ($i = 0; $i < 10; $i++) {
        $drawn[] = $first->int(1, 1_000_000);
        $redrawn[] = $second->int(1, 1_000_000);
    }

    expect($redrawn)->toBe($drawn)
        ->and(array_unique($drawn))->not->toHaveCount(1);
});

it('gives a different sequence for a different seed', function () {
    $draw = function (int $seed): array {
        $random = new SeededRandom($seed);

        return array_map(fn () => $random->int(1, 1_000_000), range(1, 10));
    };

    expect($draw(1))->not->toBe($draw(2));
});

it('remembers the seed it made up, so a plan can be rolled again', function () {
    $random = SeededRandom::fromSeed();

    expect($random->seed)->toBeInt()
        ->and(SeededRandom::fromSeed($random->seed)->int(1, 1_000))
        ->toBe((new SeededRandom($random->seed))->int(1, 1_000));
});

it('takes the seed it is given', function () {
    expect(SeededRandom::fromSeed(42)->seed)->toBe(42);
});

it('picks in proportion to the weights', function () {
    // Three to one, over enough draws for that to show.
    $counts = [0, 0];

    for ($seed = 1; $seed <= 400; $seed++) {
        $counts[(new SeededRandom($seed))->weightedIndex([3, 1])]++;
    }

    expect($counts[0])->toBeGreaterThan($counts[1] * 2)
        ->and($counts[1])->toBeGreaterThan(0);
});

it('never picks something weighted zero while anything else has weight', function () {
    for ($seed = 1; $seed <= 50; $seed++) {
        expect((new SeededRandom($seed))->weightedIndex([0, 5, 0]))->toBe(1);
    }
});

it('falls back to an even chance when everything weighs nothing', function () {
    $counts = [0, 0, 0];

    for ($seed = 1; $seed <= 90; $seed++) {
        $counts[(new SeededRandom($seed))->weightedIndex([0, 0, 0])]++;
    }

    expect(array_sum($counts))->toBe(90)
        ->and(min($counts))->toBeGreaterThan(0);
});

it('always picks the only option there is', function () {
    expect((new SeededRandom(1))->weightedIndex([7]))->toBe(0)
        ->and((new SeededRandom(1))->weightedIndex([0]))->toBe(0);
});
