<?php

namespace App\Planning;

use App\Enums\EffortTier;
use Carbon\CarbonImmutable;

/**
 * Dinner for one night: every picking rule, in one place.
 *
 * The rules, in the order they are applied (ticket MM-6, AppPlan.md "Smart Meal
 * Randomization"):
 *
 *   1. Nobody cooking? No recipe. That is leftovers or eating out, not a failure.
 *   2. How much time is there? The weekly schedule says, and a solo night for my
 *      partner narrows it (PlanningRules).
 *   3. Who can cook it? My nights take recipes tagged me or both, my partner's
 *      take partner or both, and on a night we cook together anything goes.
 *   4. Does it fit the time? A recipe's effort tier must be at most the time there
 *      is.
 *   5. Is it still free? Nothing is used twice in one window, and a freezer meal
 *      needs a portion left.
 *   6. Then chance, weighted towards meals we haven't cooked recently — so the
 *      vault gets turned over instead of the same three dinners coming up, which
 *      is the whole point of randomizing.
 *
 * Nothing fits? Say so, with the reason, and let the rest of the plan go ahead.
 *
 * A freezer meal carries no history, so it weighs the same as a recipe we have
 * never cooked: on a chaos night the stash is what it is for. It is still only
 * one portion per window, because no meal repeats inside a window.
 */
final class NightPicker
{
    public function __construct(private readonly PlanningRules $rules) {}

    public static function fromSettings(): self
    {
        return new self(PlanningRules::fromSettings());
    }

    public function rules(): PlanningRules
    {
        return $this->rules;
    }

    /**
     * Choose a meal for one night.
     *
     * The chosen meal is taken from the pool, so a later night in the same window
     * cannot pick it again and a freezer portion cannot be double-booked.
     *
     * @param  array<int, string>  $exclude  meal keys this roll must not land on —
     *                                       how a re-roll avoids handing back the
     *                                       same dinner
     */
    public function pick(NightBrief $brief, MealPool $pool, SeededRandom $random, array $exclude = []): Pick
    {
        if (! $brief->cooks()) {
            return Pick::nightOff($brief, $brief->note);
        }

        $timeAvailable = $this->rules->timeAvailableOn($brief);
        $excluded = array_fill_keys($exclude, true);

        $ruledOutByCook = 0;
        $ruledOutByTime = 0;
        $recipesTaken = 0;
        $recipes = [];

        foreach ($pool->recipes() as $option) {
            if (! $option->cookableOn($brief->cook)) {
                $ruledOutByCook++;

                continue;
            }

            if (! $option->fitsIn($timeAvailable)) {
                $ruledOutByTime++;

                continue;
            }

            if ($pool->isUsed($option->key()) || isset($excluded[$option->key()])) {
                $recipesTaken++;

                continue;
            }

            $recipes[] = $option;
        }

        $freezerAllowed = $this->rules->allowsFreezerMealOn($timeAvailable);
        $freezerEmpty = 0;
        $freezerTaken = 0;
        $freezerMeals = [];

        if ($freezerAllowed) {
            foreach ($pool->freezerMeals() as $option) {
                if ($pool->portionsAvailable($option) < 1) {
                    $freezerEmpty++;

                    continue;
                }

                if ($pool->isUsed($option->key()) || isset($excluded[$option->key()])) {
                    $freezerTaken++;

                    continue;
                }

                $freezerMeals[] = $option;
            }
        }

        /** @var array<int, MealOption> $candidates */
        $candidates = [...$recipes, ...$freezerMeals];

        if ($candidates === []) {
            return Pick::nothingFits($brief, $this->nothingFitsReason(
                brief: $brief,
                timeAvailable: $timeAvailable,
                recipesInVault: count($pool->recipes()),
                ruledOutByCook: $ruledOutByCook,
                ruledOutByTime: $ruledOutByTime,
                recipesTaken: $recipesTaken,
                freezerInStash: count($pool->freezerMeals()),
                freezerAllowed: $freezerAllowed,
                freezerEmpty: $freezerEmpty,
                freezerTaken: $freezerTaken,
            ));
        }

        $chosen = $candidates[$random->weightedIndex(array_map(
            fn (MealOption $option) => $this->weight($option, $brief->date),
            $candidates,
        ))];

        $pool->take($chosen);

        return $chosen instanceof FreezerOption
            ? Pick::freezerMeal($brief, $chosen)
            : Pick::recipe($brief, $chosen);
    }

    /**
     * Would this night take this meal at all?
     *
     * Only the rules that depend on the night — who is cooking and how long they
     * have. Used when a one-off override changes a night under a meal that was
     * already chosen: a 60-minute project survives my Saturday becoming a
     * 30-minute night only if it still fits, which it doesn't.
     */
    public function accepts(NightBrief $brief, MealOption $option): bool
    {
        if (! $brief->cooks()) {
            return false;
        }

        $timeAvailable = $this->rules->timeAvailableOn($brief);

        return match (true) {
            $option instanceof RecipeOption => $option->cookableOn($brief->cook)
                && $option->fitsIn($timeAvailable),
            $option instanceof FreezerOption => $this->rules->allowsFreezerMealOn($timeAvailable),
            default => false,
        };
    }

    /**
     * How strongly chance should favour this meal.
     *
     * One more day since we last ate it is one more ticket in the draw, up to the
     * household's lookback; anything older than that, or never cooked at all, is
     * as favoured as it gets. Everything keeps at least one ticket, so a vault we
     * cooked through yesterday still produces dinner.
     */
    private function weight(MealOption $option, CarbonImmutable $night): int
    {
        $lookback = max(0, $this->rules->varietyLookbackDays);
        $lastCooked = $option->lastCookedOn();

        if ($lastCooked === null) {
            return $lookback + 1;
        }

        return 1 + max(0, min(CalendarDate::daysBetween($lastCooked, $night), $lookback));
    }

    /**
     * Why nothing fitted, in a sentence the household can act on.
     *
     * "Nothing fits" on its own is useless: the answer is either "add a quick
     * recipe she likes", "cook together that night" or "restock the freezer", and
     * which one depends on what ruled everything out.
     */
    private function nothingFitsReason(
        NightBrief $brief,
        EffortTier $timeAvailable,
        int $recipesInVault,
        int $ruledOutByCook,
        int $ruledOutByTime,
        int $recipesTaken,
        int $freezerInStash,
        bool $freezerAllowed,
        int $freezerEmpty,
        int $freezerTaken,
    ): string {
        $parts = [];

        if ($recipesInVault === 0) {
            $parts[] = 'the recipe vault is empty';
        } else {
            if ($ruledOutByCook > 0) {
                $parts[] = sprintf(
                    '%d of %d recipes are not tagged for %s',
                    $ruledOutByCook,
                    $recipesInVault,
                    $brief->cook->label(),
                );
            }

            if ($ruledOutByTime > 0) {
                $parts[] = sprintf(
                    '%d need more than %d min',
                    $ruledOutByTime,
                    $timeAvailable->minutes(),
                );
            }

            if ($recipesTaken > 0) {
                $parts[] = sprintf('%d are already spoken for this window', $recipesTaken);
            }
        }

        // An empty stash first: when there is nothing in the freezer, that is the
        // more useful fact than which nights the freezer is saved for.
        $parts[] = match (true) {
            $freezerInStash === 0 => 'the freezer stash is empty',
            ! $freezerAllowed => sprintf(
                'freezer meals are kept for %s nights',
                EffortTier::Chaos15->label(),
            ),
            $freezerTaken > 0 && $freezerEmpty === 0 => 'the freezer meals left are already spoken for this window',
            default => 'no freezer meal has a portion left',
        };

        return sprintf(
            'Nothing fits a %s night with %s cooking%s: %s.',
            $timeAvailable->label(),
            $brief->cook->label(),
            $this->capNote($brief, $timeAvailable),
            implode('; ', $parts),
        );
    }

    /**
     * Say so when the night is shorter than the schedule because of the solo rule,
     * so "needs more than 15 min" doesn't look like a mistake on a 60-minute night.
     */
    private function capNote(NightBrief $brief, EffortTier $timeAvailable): string
    {
        if (! $brief->isPartnerSolo() || $timeAvailable === $brief->timeBudget) {
            return '';
        }

        return sprintf(
            ' (a solo night is capped at %s, from %s)',
            $timeAvailable->label(),
            $brief->timeBudget->label(),
        );
    }
}
