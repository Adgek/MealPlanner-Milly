<?php

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Planning\MealPool;
use App\Planning\NightBrief;
use App\Planning\NightPicker;
use App\Planning\Pick;
use App\Planning\PlanningRules;
use App\Planning\SeededRandom;

/*
 * Every picking rule for a single night, one rule at a time.
 *
 * Unit tests: no framework, no database (AGENTS.md). The picker is handed plain
 * recipes, a plain night and a seeded source of chance, which is what makes
 * "dinner came out wrong" a thing a test can catch rather than a thing the
 * household finds out at 6pm.
 *
 * Where a rule is about what *can* come up rather than what did, the test rolls
 * the night with many seeds and looks at the whole set of outcomes — otherwise a
 * lucky seed would hide a broken rule.
 */

/**
 * Every meal this night can produce, over many seeds.
 *
 * @return array<int, string>
 */
function everyOutcome(NightBrief $night, array $recipes, array $freezerMeals = [], ?PlanningRules $rules = null, int $rolls = 60): array
{
    $outcomes = [];

    for ($seed = 1; $seed <= $rolls; $seed++) {
        $pick = (new NightPicker($rules ?? theRules()))->pick(
            $night,
            new MealPool($recipes, $freezerMeals),
            new SeededRandom($seed),
        );

        $outcomes[] = $pick->selection === NightSelection::NothingFits
            ? 'nothing fits'
            : (string) $pick->mealName;
    }

    return array_values(array_unique($outcomes));
}

it('uses recipes tagged me or both on my nights', function () {
    $outcomes = everyOutcome(
        aNight('2026-01-12', CookAssignment::Me),
        [
            aRecipe(1, CookableBy::Me, name: 'Mine'),
            aRecipe(2, CookableBy::Both, name: 'Either of us'),
            aRecipe(3, CookableBy::Partner, name: 'Hers'),
        ],
    );

    expect($outcomes)->toContain('Mine')
        ->and($outcomes)->toContain('Either of us')
        ->and($outcomes)->not->toContain('Hers');
});

it('uses recipes tagged partner or both on my partner’s nights', function () {
    $outcomes = everyOutcome(
        aNight('2026-01-12', CookAssignment::Partner, EffortTier::Chaos15),
        [
            aRecipe(1, CookableBy::Partner, EffortTier::Chaos15, name: 'Hers'),
            aRecipe(2, CookableBy::Both, EffortTier::Chaos15, name: 'Either of us'),
            aRecipe(3, CookableBy::Me, EffortTier::Chaos15, name: 'Mine'),
        ],
    );

    expect($outcomes)->toContain('Hers')
        ->and($outcomes)->toContain('Either of us')
        ->and($outcomes)->not->toContain('Mine');
});

it('uses any recipe on a night we cook together', function () {
    $outcomes = everyOutcome(
        aNight('2026-01-12', CookAssignment::Together),
        [
            aRecipe(1, CookableBy::Me, name: 'Mine'),
            aRecipe(2, CookableBy::Partner, name: 'Hers'),
            aRecipe(3, CookableBy::Both, name: 'Either of us'),
        ],
    );

    expect($outcomes)->toHaveCount(3);
});

it('only uses a recipe the night has time for', function () {
    $outcomes = everyOutcome(
        aNight('2026-01-12', CookAssignment::Together, EffortTier::Workhorse30),
        [
            aRecipe(1, effort: EffortTier::Chaos15, name: 'Quick'),
            aRecipe(2, effort: EffortTier::Workhorse30, name: 'Workhorse'),
            aRecipe(3, effort: EffortTier::Project60, name: 'Project'),
        ],
    );

    // A quick meal is fine when there is more time; an hour-long project is not.
    expect($outcomes)->toContain('Quick')
        ->and($outcomes)->toContain('Workhorse')
        ->and($outcomes)->not->toContain('Project');
});

it('can still use a quick recipe on a long night', function () {
    $outcomes = everyOutcome(
        aNight('2026-01-12', CookAssignment::Together, EffortTier::Project60),
        [aRecipe(1, effort: EffortTier::Chaos15, name: 'Quick')],
    );

    expect($outcomes)->toBe(['Quick']);
});

it('gives a partner solo night only her 15-min recipes or a freezer meal', function () {
    // The rule from docs/decisions.md, and the one the household notices first:
    // she is never handed something she doesn't want to make on her own.
    // The weekly schedule says there is an hour on this night; a solo night is
    // capped anyway.
    $outcomes = everyOutcome(
        aNight('2026-01-13', CookAssignment::Partner, EffortTier::Project60),
        [
            aRecipe(1, CookableBy::Partner, EffortTier::Chaos15, name: 'Quick pasta'),
            aRecipe(2, CookableBy::Both, EffortTier::Chaos15, name: 'Pupusas'),
            // Hers, but not quick.
            aRecipe(3, CookableBy::Partner, EffortTier::Workhorse30, name: 'Her workhorse'),
            aRecipe(4, CookableBy::Partner, EffortTier::Project60, name: 'Her project'),
            // Quick, but mine.
            aRecipe(5, CookableBy::Me, EffortTier::Chaos15, name: 'My quick one'),
        ],
        [aFreezerMeal(1, 3, name: 'Frozen minestrone')],
        rolls: 120,
    );

    expect($outcomes)->toContain('Quick pasta')
        ->and($outcomes)->toContain('Pupusas')
        ->and($outcomes)->toContain('Frozen minestrone')
        ->and($outcomes)->not->toContain('Her workhorse')
        ->and($outcomes)->not->toContain('Her project')
        ->and($outcomes)->not->toContain('My quick one')
        ->and($outcomes)->not->toContain('nothing fits');
});

it('respects a household that raises the solo cap', function () {
    // The cap is a setting, not a constant: at 30 minutes her workhorse is in.
    $outcomes = everyOutcome(
        aNight('2026-01-13', CookAssignment::Partner, EffortTier::Project60),
        [
            aRecipe(1, CookableBy::Partner, EffortTier::Workhorse30, name: 'Her workhorse'),
            aRecipe(2, CookableBy::Partner, EffortTier::Project60, name: 'Her project'),
        ],
        rules: theRules(partnerSoloEffortCap: EffortTier::Workhorse30),
    );

    expect($outcomes)->toBe(['Her workhorse']);
});

it('never gives a solo night more time than the schedule has', function () {
    // The cap narrows a night, it never widens one: a 15-minute Tuesday stays a
    // 15-minute Tuesday even with the cap set to an hour.
    $outcomes = everyOutcome(
        aNight('2026-01-13', CookAssignment::Partner, EffortTier::Chaos15),
        [
            aRecipe(1, CookableBy::Partner, EffortTier::Chaos15, name: 'Quick pasta'),
            aRecipe(2, CookableBy::Partner, EffortTier::Workhorse30, name: 'Her workhorse'),
        ],
        rules: theRules(partnerSoloEffortCap: EffortTier::Project60),
    );

    expect($outcomes)->toBe(['Quick pasta']);
});

it('gives a nobody night no recipe at all', function () {
    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-12', CookAssignment::Nobody, note: 'Out for dinner'),
        new MealPool([aRecipe(1)], [aFreezerMeal(1)]),
        new SeededRandom(1),
    );

    expect($pick->selection)->toBe(NightSelection::NightOff)
        ->and($pick->recipeId)->toBeNull()
        ->and($pick->freezerItemId)->toBeNull()
        ->and($pick->hasMeal())->toBeFalse()
        ->and($pick->describe())->toBe('Nobody cooking — Out for dinner');
});

it('spends a freezer portion when it plans a freezer meal', function () {
    $pool = new MealPool([], [aFreezerMeal(7, 2, name: 'Frozen minestrone')]);

    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-12', CookAssignment::Partner, EffortTier::Chaos15),
        $pool,
        new SeededRandom(1),
    );

    expect($pick->selection)->toBe(NightSelection::FreezerItem)
        ->and($pick->freezerItemId)->toBe(7)
        ->and($pick->describe())->toBe('Frozen minestrone (from the freezer)')
        // One portion is now spoken for, and the meal itself is used up for the window.
        ->and($pool->portionsAvailable(aFreezerMeal(7, 2)))->toBe(1)
        ->and($pool->isUsed('freezer:7'))->toBeTrue();
});

it('will not plan a freezer meal with no portions left', function () {
    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-12', CookAssignment::Partner, EffortTier::Chaos15),
        new MealPool([], [aFreezerMeal(7, 0)]),
        new SeededRandom(1),
    );

    expect($pick->selection)->toBe(NightSelection::NothingFits)
        ->and($pick->note)->toContain('no freezer meal has a portion left');
});

it('keeps the freezer stash for chaos nights', function () {
    // There is half an hour on this night, so there is time to cook: the stash
    // stays shut and the night takes the recipe.
    $outcomes = everyOutcome(
        aNight('2026-01-12', CookAssignment::Together, EffortTier::Workhorse30),
        [aRecipe(1, name: 'Bulgogi')],
        [aFreezerMeal(1, 4, name: 'Frozen minestrone')],
    );

    expect($outcomes)->toBe(['Bulgogi']);
});

it('lets a household open the freezer on any night', function () {
    $outcomes = everyOutcome(
        aNight('2026-01-12', CookAssignment::Together, EffortTier::Workhorse30),
        [aRecipe(1, name: 'Bulgogi')],
        [aFreezerMeal(1, 4, name: 'Frozen minestrone')],
        rules: theRules(freezerMealsOnChaosNightsOnly: false),
    );

    expect($outcomes)->toContain('Bulgogi')
        ->and($outcomes)->toContain('Frozen minestrone');
});

it('does not use a meal the window has already taken', function () {
    $outcomes = [];

    for ($seed = 1; $seed <= 20; $seed++) {
        // A fresh pool each roll: the bulgogi and the soup are spoken for by
        // another night of the same window, so only the tacos are left.
        $pool = new MealPool(
            [
                aRecipe(1, effort: EffortTier::Chaos15, name: 'Bulgogi'),
                aRecipe(2, effort: EffortTier::Chaos15, name: 'Tacos'),
            ],
            [aFreezerMeal(1, 2, name: 'Frozen minestrone')],
        );
        $pool->markUsed('recipe:1');
        $pool->markUsed('freezer:1');

        $outcomes[] = (new NightPicker(theRules()))->pick(
            aNight('2026-01-12', CookAssignment::Together, EffortTier::Chaos15),
            $pool,
            new SeededRandom($seed),
        )->mealName;
    }

    expect(array_unique($outcomes))->toBe(['Tacos']);
});

it('favours meals we have not cooked recently', function () {
    // Both fit the night; one was cooked yesterday and one has never come up.
    // Chance still decides, but it should lean hard the other way.
    $chosen = ['Cooked yesterday' => 0, 'Never cooked' => 0];

    for ($seed = 1; $seed <= 200; $seed++) {
        $pick = (new NightPicker(theRules(varietyLookbackDays: 21)))->pick(
            aNight('2026-01-20'),
            new MealPool([
                aRecipe(1, lastCookedOn: '2026-01-19', name: 'Cooked yesterday'),
                aRecipe(2, name: 'Never cooked'),
            ]),
            new SeededRandom($seed),
        );

        $chosen[(string) $pick->mealName]++;
    }

    expect($chosen['Never cooked'])->toBeGreaterThan($chosen['Cooked yesterday'] * 3)
        // ...but the one we just ate is not banned, in case it is all there is.
        ->and($chosen['Cooked yesterday'])->toBeGreaterThan(0);
});

it('treats anything older than the lookback as equally overdue', function () {
    $chosen = ['Three weeks ago' => 0, 'Three months ago' => 0];

    for ($seed = 1; $seed <= 200; $seed++) {
        $pick = (new NightPicker(theRules(varietyLookbackDays: 21)))->pick(
            aNight('2026-04-01'),
            new MealPool([
                aRecipe(1, lastCookedOn: '2026-03-11', name: 'Three weeks ago'),
                aRecipe(2, lastCookedOn: '2026-01-01', name: 'Three months ago'),
            ]),
            new SeededRandom($seed),
        );

        $chosen[(string) $pick->mealName]++;
    }

    // Both are at the ceiling, so this is a coin toss, not a preference.
    expect($chosen['Three weeks ago'])->toBeGreaterThan(60)
        ->and($chosen['Three months ago'])->toBeGreaterThan(60);
});

it('still produces dinner when every recipe was cooked today', function () {
    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-20'),
        new MealPool([aRecipe(1, lastCookedOn: '2026-01-20', name: 'Bulgogi')]),
        new SeededRandom(1),
    );

    expect($pick->mealName)->toBe('Bulgogi');
});

it('repeats exactly with the same seed, and can differ with another', function () {
    $roll = fn (int $seed) => (new NightPicker(theRules()))->pick(
        aNight('2026-01-12'),
        new MealPool([aRecipe(1), aRecipe(2), aRecipe(3), aRecipe(4), aRecipe(5)]),
        new SeededRandom($seed),
    )->mealName;

    expect($roll(99))->toBe($roll(99));

    $everySeed = array_map($roll, range(1, 25));

    expect(array_unique($everySeed))->not->toHaveCount(1);
});

it('says nothing fits, and why, when the rules rule everything out', function () {
    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-13', CookAssignment::Partner, EffortTier::Project60),
        new MealPool(
            [
                aRecipe(1, CookableBy::Me, EffortTier::Chaos15),
                aRecipe(2, CookableBy::Me, EffortTier::Workhorse30),
                aRecipe(3, CookableBy::Partner, EffortTier::Project60),
                aRecipe(4, CookableBy::Both, EffortTier::Workhorse30),
            ],
            [aFreezerMeal(1, 0)],
        ),
        new SeededRandom(1),
    );

    expect($pick->selection)->toBe(NightSelection::NothingFits)
        ->and($pick->hasMeal())->toBeFalse()
        ->and($pick->note)->toBe(
            'Nothing fits a 15-min chaos night with Partner cooking '
            .'(a solo night is capped at 15-min chaos, from 60-min+ project): '
            .'2 of 4 recipes are not tagged for Partner; 2 need more than 15 min; '
            .'no freezer meal has a portion left.'
        );
});

it('says when the freezer is being kept for chaos nights', function () {
    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-12', CookAssignment::Me, EffortTier::Workhorse30),
        new MealPool([aRecipe(1, CookableBy::Partner)], [aFreezerMeal(1, 5)]),
        new SeededRandom(1),
    );

    expect($pick->note)->toBe(
        'Nothing fits a 30-min workhorse night with Me cooking: '
        .'1 of 1 recipes are not tagged for Me; freezer meals are kept for 15-min chaos nights.'
    );
});

it('says when the vault is empty', function () {
    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-12'),
        new MealPool,
        new SeededRandom(1),
    );

    expect($pick->note)->toBe(
        'Nothing fits a 30-min workhorse night with Together cooking: '
        .'the recipe vault is empty; the freezer stash is empty.'
    );
});

it('says when the window has already used everything that fits', function () {
    $pool = new MealPool([aRecipe(1, effort: EffortTier::Chaos15, name: 'Bulgogi')], [aFreezerMeal(1, 2)]);
    $pool->markUsed('recipe:1');
    $pool->markUsed('freezer:1');

    $pick = (new NightPicker(theRules()))->pick(
        aNight('2026-01-12', CookAssignment::Together, EffortTier::Chaos15),
        $pool,
        new SeededRandom(1),
    );

    expect($pick->note)->toContain('1 are already spoken for this window')
        ->and($pick->note)->toContain('the freezer meals left are already spoken for this window');
});

it('can be told to avoid a particular meal, which is how a re-roll works', function () {
    $outcomes = [];

    for ($seed = 1; $seed <= 25; $seed++) {
        $outcomes[] = (new NightPicker(theRules()))->pick(
            aNight('2026-01-12'),
            new MealPool([aRecipe(1, name: 'Bulgogi'), aRecipe(2, name: 'Tacos')]),
            new SeededRandom($seed),
            exclude: ['recipe:1'],
        )->mealName;
    }

    expect(array_unique($outcomes))->toBe(['Tacos']);
});

it('knows whether a night would take a meal it already has', function () {
    $picker = new NightPicker(theRules());
    $project = aRecipe(1, CookableBy::Both, EffortTier::Project60);
    $freezerMeal = aFreezerMeal(1, 2);

    expect($picker->accepts(aNight('2026-01-12', CookAssignment::Me, EffortTier::Project60), $project))->toBeTrue()
        // The same night with less time, or in my partner's hands alone, would not.
        ->and($picker->accepts(aNight('2026-01-12', CookAssignment::Me, EffortTier::Workhorse30), $project))->toBeFalse()
        ->and($picker->accepts(aNight('2026-01-12', CookAssignment::Partner, EffortTier::Project60), $project))->toBeFalse()
        // Nobody is cooking, so nothing is on.
        ->and($picker->accepts(aNight('2026-01-12', CookAssignment::Nobody), $project))->toBeFalse()
        // A freezer meal belongs on a chaos night.
        ->and($picker->accepts(aNight('2026-01-12', CookAssignment::Me, EffortTier::Chaos15), $freezerMeal))->toBeTrue()
        ->and($picker->accepts(aNight('2026-01-12', CookAssignment::Me, EffortTier::Workhorse30), $freezerMeal))->toBeFalse();
});

it('describes a pick the way a plan reads it', function () {
    $recipe = Pick::recipe(aNight('2026-01-12'), aRecipe(1, name: 'Chili'));
    $freezer = Pick::freezerMeal(aNight('2026-01-12'), aFreezerMeal(1, 2, name: 'Frozen minestrone'));

    expect($recipe->describe())->toBe('Chili')
        ->and($recipe->mealKey())->toBe('recipe:1')
        ->and($freezer->describe())->toBe('Frozen minestrone (from the freezer)')
        ->and($freezer->mealKey())->toBe('freezer:1')
        ->and(Pick::unplanned(aNight('2026-01-12'))->describe())->toBe('Not planned yet')
        ->and(Pick::nothingFits(aNight('2026-01-12'), 'because')->describe())->toBe('Nothing fits');
});
