<?php

namespace App\Planning;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Models\FreezerItem;
use App\Models\MealHistoryEntry;
use App\Models\Plan;
use App\Models\PlanNight;
use App\Models\Recipe;
use App\Planning\Exceptions\PlanningException;
use App\Settings\HouseholdSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The meal picker, as the rest of the app uses it.
 *
 * Everything other tickets need is here, and the rules themselves are not: this
 * class reads Settings, loads the vault, writes the plan and keeps the freezer
 * count honest, while NightPicker and WindowPicker decide what dinner is from
 * plain values. That split is what lets every rule be unit-tested without a
 * database (AGENTS.md).
 *
 * The five functions the other tickets call:
 *
 *   planWindow()    plan a window, saving it (MM-7 grocery list, MM-9 the post)
 *   rerollNight()   roll one night again (MM-8's re-roll button)
 *   lockNight() / unlockNight()
 *   chooseMeal()    swap in a particular recipe or freezer meal by hand
 *   setOverride()   change who cooks or how long they have, for one date only
 *
 * Plus the two that keep the data true: previewWindow() for a dry run, and
 * recordPastNightsAsCooked() for meal history.
 */
final class MealPlanner
{
    /**
     * Every collaborator is optional and read from Settings when it is missing,
     * so `new MealPlanner` is the household's own configuration and a test can
     * hand in a different week or a different rule set.
     */
    public function __construct(
        private readonly ?ShoppingCalendar $calendar = null,
        private readonly ?WeeklyCookSchedule $schedule = null,
        private readonly ?WindowPicker $picker = null,
    ) {}

    // -----------------------------------------------------------------
    // The calendar
    // -----------------------------------------------------------------

    /**
     * Today, on the kitchen clock.
     *
     * Not the server's date: at 11pm in Toronto, UTC is already tomorrow, and
     * planning from the wrong day would shift the whole window (docs/decisions.md).
     */
    public function today(): CarbonImmutable
    {
        return CalendarDate::today(HouseholdSettings::timeZone());
    }

    /**
     * The next window to plan: the one the next shop is for.
     */
    public function nextWindow(?DateTimeInterface $now = null): ShoppingWindow
    {
        return $this->calendar()->nextWindow($this->dateOf($now));
    }

    /**
     * The window in force: the one tonight's dinner belongs to.
     */
    public function currentWindow(?DateTimeInterface $now = null): ShoppingWindow
    {
        return $this->calendar()->currentWindow($this->dateOf($now));
    }

    /**
     * The window a shop on a given date covers.
     */
    public function windowFor(DateTimeInterface $shoppingDate): ShoppingWindow
    {
        return $this->calendar()->windowFor($shoppingDate);
    }

    /**
     * The saved plan for a window, if there is one.
     */
    public function planFor(ShoppingWindow|DateTimeInterface $window): ?Plan
    {
        $shoppingDate = $window instanceof ShoppingWindow
            ? $window->shoppingDate
            : CalendarDate::of($window);

        return Plan::query()->firstWhere('shopping_date', $shoppingDate->toDateString());
    }

    // -----------------------------------------------------------------
    // Planning a window
    // -----------------------------------------------------------------

    /**
     * Plan a window and save it.
     *
     * Called again for the same window it fills in whatever is still open and
     * leaves the rest alone; with $replan it rolls every night again except the
     * ones the household locked. Nights that carry a one-off override keep their
     * cook and time; the others are refreshed from the weekly schedule, so a
     * change in Settings reaches a plan that hasn't been shopped yet.
     *
     * "Still open" includes a night nothing fitted (PlanNight::needsPicking()):
     * its stored reason is advice, and taking the advice has to be enough.
     *
     * Meal history is caught up first: variety is measured against what we
     * actually ate, and nothing else in the app does that yet.
     */
    public function planWindow(?ShoppingWindow $window = null, ?int $seed = null, bool $replan = false): Plan
    {
        $window ??= $this->nextWindow();

        $this->recordPastNightsAsCooked();

        return DB::transaction(function () use ($window, $seed, $replan) {
            $plan = $this->savePlan($window);

            $this->claimNightsOfOtherPlans($plan, $window);
            $this->syncNightsWith($plan, $window);
            $this->fillNights($plan, $seed, $replan);

            return $plan->load(['nights.recipe', 'nights.freezerItem']);
        });
    }

    /**
     * What planning the window would choose, without keeping it.
     *
     * It really does plan — same code, same seed, same freezer arithmetic — inside
     * a transaction that is then rolled back, because a preview that took a
     * different path would be a preview of the wrong thing. Nothing survives but
     * the picks, which are plain values. (Auto-increment ids are spent; nothing
     * else is.)
     *
     * @return array<int, Pick>
     */
    public function previewWindow(?ShoppingWindow $window = null, ?int $seed = null, bool $replan = false): array
    {
        DB::beginTransaction();

        try {
            return $this->picksFor($this->planWindow($window, $seed, $replan));
        } finally {
            DB::rollBack();
        }
    }

    /**
     * A saved plan as a list of picks, so a saved window prints exactly like a
     * freshly rolled one.
     *
     * @return array<int, Pick>
     */
    public function picksFor(Plan $plan): array
    {
        return $plan->load(['nights.recipe', 'nights.freezerItem'])
            ->nights
            ->map(fn (PlanNight $night) => Pick::fromPlanNight($night))
            ->all();
    }

    // -----------------------------------------------------------------
    // One night at a time
    // -----------------------------------------------------------------

    /**
     * Roll one night again.
     *
     * The night lets go of what it had first — a freezer portion goes back in the
     * stash — and the roll will not hand back the same meal, because a re-roll
     * that changes nothing is not a re-roll. If nothing else fits, the night keeps
     * what it had rather than losing a perfectly good dinner, and the note says
     * why.
     *
     * A locked night is refused: unlock it first. So is a night that has already
     * gone by, because that is no longer a plan — it is what the household ate.
     */
    public function rerollNight(PlanNight $night, ?int $seed = null): PlanNight
    {
        if ($night->is_locked) {
            throw PlanningException::lockedNight($night);
        }

        $this->refuseIfPast($night);

        return DB::transaction(function () use ($night, $seed) {
            $previous = [
                'selection' => $night->selection,
                'recipe_id' => $night->recipe_id,
                'freezer_item_id' => $night->freezer_item_id,
            ];
            $previousKey = Pick::fromPlanNight($night)->mealKey();
            $previousMeal = $night->hasMeal() ? $this->mealNameOf($night) : null;

            $pick = $this->rollOneNight(
                $night,
                $seed,
                $previousKey === null ? [] : [$previousKey],
            );

            if ($pick->selection === NightSelection::NothingFits && $previousMeal !== null) {
                // The picker's reason travels with the note rather than being
                // written into it: the note is the planner's own words, and the
                // reason still has to be rendered with the household's names.
                $this->restore($night, $previous, $pick->noted(sprintf(
                    'Kept %s: nothing else fits.',
                    $previousMeal,
                )));
            }

            return $night;
        });
    }

    /**
     * Lock a night, so a re-plan leaves it exactly as it is.
     */
    public function lockNight(PlanNight $night): PlanNight
    {
        $night->is_locked = true;
        $night->save();

        return $night;
    }

    public function unlockNight(PlanNight $night): PlanNight
    {
        $night->is_locked = false;
        $night->save();

        return $night;
    }

    /**
     * Put a particular recipe or freezer meal on a night.
     *
     * The household's own choice, so the picking rules do not get a vote: if they
     * want the 60-minute project on a Tuesday, that is the plan ("until you shop,
     * you can re-roll, lock or swap any night", docs/decisions.md). The one thing
     * that is still enforced is the freezer count — a portion that isn't there
     * cannot be planned, or MM-12 would be warning about a number that never
     * happened.
     *
     * Pass lock: true to make the choice survive the next re-plan.
     *
     * A night that has already gone by is refused: swapping its meal would let go
     * of a freezer portion that was eaten, and rewrite the history the variety
     * rule is measured against.
     */
    public function chooseMeal(PlanNight $night, Recipe|FreezerItem $meal, bool $lock = false): PlanNight
    {
        $this->refuseIfPast($night);

        return DB::transaction(function () use ($night, $meal, $lock) {
            // First, so swapping a freezer meal for itself or for another portion
            // of the same item hands the old one back before taking the new one.
            $this->releaseNight($night);

            if ($meal instanceof FreezerItem) {
                $this->reservePortionFor($night, $meal->getKey());
                $night->selection = NightSelection::FreezerItem;
                $night->freezer_item_id = $meal->getKey();
            } else {
                $night->selection = NightSelection::Recipe;
                $night->recipe_id = $meal->getKey();
            }

            if ($lock) {
                $night->is_locked = true;
            }

            $night->save();

            return $night->load(['recipe', 'freezerItem']);
        });
    }

    /**
     * Change one date only: who cooks, how long they have, or just why.
     *
     * This is the one-off override — travel, leftovers, eating out — and it lives
     * on the night rather than in the weekly schedule, so the household's normal
     * week is untouched and a re-plan keeps the override. Arguments left out mean
     * "leave that as it is"; clearOverride() is how a night goes back to the
     * normal week.
     *
     * The meal is kept if it still fits the night. If it doesn't — my 60-minute
     * Saturday becoming my partner's solo night, or anybody's night becoming
     * nobody's — the night is rolled again, and the freezer portion goes back if
     * there was one. A locked night is left alone.
     */
    public function setOverride(
        PlanNight $night,
        ?CookAssignment $cook = null,
        ?EffortTier $timeBudget = null,
        ?string $note = null,
        bool $repick = true,
    ): PlanNight {
        if ($cook === null && $timeBudget === null && $note === null) {
            return $night;
        }

        return DB::transaction(function () use ($night, $cook, $timeBudget, $note, $repick) {
            $night->cook = $cook ?? $night->cook;
            $night->time_budget = $timeBudget ?? $night->time_budget;
            $night->override_note = $note ?? $night->override_note;
            $night->overrides_schedule = true;
            $night->save();

            if ($repick) {
                $this->repickIfNeeded($night);
            }

            return $night;
        });
    }

    /**
     * Put a night back on the normal week, dropping its override.
     */
    public function clearOverride(PlanNight $night, bool $repick = true): PlanNight
    {
        return DB::transaction(function () use ($night, $repick) {
            $brief = $this->schedule()->nightOn($night->date);

            $night->cook = $brief->cook;
            $night->time_budget = $brief->timeBudget;
            $night->override_note = null;
            $night->overrides_schedule = false;
            $night->save();

            if ($repick) {
                $this->repickIfNeeded($night);
            }

            return $night;
        });
    }

    // -----------------------------------------------------------------
    // Meal history
    // -----------------------------------------------------------------

    /**
     * Write every night that has passed into meal history, and say how many were
     * new.
     *
     * What variety is measured against. Nothing confirms that a planned dinner was
     * actually cooked yet — a "we cooked it" button is in the parking lot
     * (docs/decisions.md) — so a planned night whose date has gone by counts as
     * cooked. It is the best evidence the app has, and being wrong only means a
     * recipe comes up again a little later than it might.
     *
     * Freezer meals are not recorded: meal history is recipes, and the portion
     * that was spent is already the record. Idempotent, so it can run as often as
     * it likes.
     *
     * $now is an instant, like every other "now" on this class, and is read as the
     * date the household is living in. That matters because it is the difference
     * between right and a day early: 8pm in Toronto is already tomorrow in UTC, so
     * a caller passing `now()` after 7pm all winter — which is exactly what MM-13's
     * scheduled catch-up is — would otherwise record tonight's dinner as cooked
     * before anybody ate it, and suppress that recipe for the whole lookback.
     */
    public function recordPastNightsAsCooked(?DateTimeInterface $now = null): int
    {
        $today = $this->dateOf($now);

        $nights = PlanNight::query()
            ->where('selection', NightSelection::Recipe->value)
            ->whereNotNull('recipe_id')
            ->where('date', '<', $today->toDateString())
            ->get();

        $recorded = 0;

        foreach ($nights as $night) {
            $entry = MealHistoryEntry::query()->firstOrCreate([
                'cooked_on' => $night->date->toDateString(),
                'recipe_id' => $night->recipe_id,
            ]);

            $recorded += $entry->wasRecentlyCreated ? 1 : 0;
        }

        return $recorded;
    }

    // -----------------------------------------------------------------
    // Settings-backed collaborators
    // -----------------------------------------------------------------

    public function calendar(): ShoppingCalendar
    {
        return $this->calendar ?? ShoppingCalendar::fromSettings();
    }

    public function schedule(): WeeklyCookSchedule
    {
        return $this->schedule ?? WeeklyCookSchedule::fromSettings();
    }

    public function picker(): WindowPicker
    {
        return $this->picker ?? WindowPicker::fromSettings();
    }

    public function nightPicker(): NightPicker
    {
        return $this->picker()->nightPicker();
    }

    // -----------------------------------------------------------------
    // Saving a plan
    // -----------------------------------------------------------------

    private function savePlan(ShoppingWindow $window): Plan
    {
        $plan = Plan::query()->firstOrNew([
            'shopping_date' => $window->shoppingDate->toDateString(),
        ]);

        // The window is stored, not derived, so a plan already out keeps the
        // nights it was built for — but while we are planning it, the current
        // shopping days win.
        $plan->first_night = $window->firstNight->toDateString();
        $plan->last_night = $window->lastNight->toDateString();
        $plan->save();

        return $plan;
    }

    /**
     * Take the nights of this window off any other plan that still claims them.
     *
     * Changing the shopping days is a first-class MM-4 action, and it moves where
     * the windows fall. The plan already out was built for the old days, so its
     * nights can land inside the window being planned now — and nothing else
     * revisits it, because syncNightsWith() only ever prunes the plan it is
     * handed. Two rows for one date is not a cosmetic problem: MM-9's daily
     * reminder and MM-14's dashboard both ask `plan_nights.date` what the
     * household is eating tonight and there is no tiebreak, the no-repeat ledger
     * is per plan so the night could duplicate a dinner either side of it, and
     * recordPastNightsAsCooked() would write two history rows for one dinner.
     *
     * The window being planned wins, because it is the shop that will actually
     * buy the food. A night still to come hands its freezer portion back on the
     * way out; a night that has gone by is history and stays with the plan that
     * fed it. A plan left with no nights is no longer a plan, so it goes; one left
     * shorter has its stored window narrowed to the nights it still has.
     */
    private function claimNightsOfOtherPlans(Plan $plan, ShoppingWindow $window): void
    {
        $claimed = PlanNight::query()
            ->where('plan_id', '!=', $plan->getKey())
            ->whereBetween('date', [
                $window->firstNight->toDateString(),
                $window->lastNight->toDateString(),
            ])
            ->get()
            ->reject(fn (PlanNight $night) => $this->hasPassed($night));

        foreach ($claimed as $night) {
            $this->releaseNight($night);
            $night->delete();
        }

        foreach ($claimed->pluck('plan_id')->unique() as $planId) {
            $this->reshapeOrDrop(Plan::query()->find($planId));
        }
    }

    /**
     * Leave a plan that has given nights up describing the nights it has left.
     */
    private function reshapeOrDrop(?Plan $plan): void
    {
        if ($plan === null) {
            return;
        }

        $nights = $plan->nights()->get();

        if ($nights->isEmpty()) {
            $plan->delete();

            return;
        }

        $plan->first_night = $nights->first()->date->toDateString();
        $plan->last_night = $nights->last()->date->toDateString();
        $plan->save();
    }

    /**
     * One night row per night of the window, each carrying the cook and the time
     * in force for that date.
     */
    private function syncNightsWith(Plan $plan, ShoppingWindow $window): void
    {
        $schedule = $this->schedule();
        $existing = $plan->nights()->get()->keyBy(fn (PlanNight $night) => $night->date->toDateString());
        $wanted = [];

        foreach ($window->nights() as $date) {
            $key = $date->toDateString();
            $wanted[$key] = true;
            $night = $existing->get($key);
            $brief = $schedule->nightOn($date);

            if ($night === null) {
                $plan->nights()->create([
                    'date' => $key,
                    'cook' => $brief->cook,
                    'time_budget' => $brief->timeBudget,
                ]);

                continue;
            }

            // A one-off override is the household's answer for this date, and a
            // locked night is settled: both outrank the weekly schedule.
            if ($night->overrides_schedule || $night->is_locked) {
                continue;
            }

            if ($night->cook === $brief->cook && $night->time_budget === $brief->timeBudget) {
                continue;
            }

            $night->cook = $brief->cook;
            $night->time_budget = $brief->timeBudget;
            $night->save();

            // The week changed under a meal that was already chosen.
            $this->releaseIfMealNoLongerFits($night);
        }

        // Nights this window no longer covers, because the shopping days changed.
        foreach ($existing as $key => $night) {
            if (isset($wanted[$key])) {
                continue;
            }

            // A night still to come hands its portion back; one that has gone by
            // ate it, so the row goes without touching the stash.
            if (! $this->hasPassed($night)) {
                $this->releaseNight($night);
            }

            $night->delete();
        }
    }

    /**
     * Roll the nights that are open, keeping the ones that are not.
     *
     * A night that has gone by is never rolled, whatever is asked: re-planning a
     * finished window would change dinners the household already cooked, and meal
     * history — the only input the variety rule has — would then record both the
     * meal they ate and the one the re-plan put there. Three dinners would become
     * six suppressed recipes.
     */
    private function fillNights(Plan $plan, ?int $seed, bool $replan): void
    {
        $nights = $plan->nights()->get();

        $toRoll = $nights->filter(
            fn (PlanNight $night) => ! $night->is_locked
                && ! $this->hasPassed($night)
                && ($replan || $night->needsPicking())
        );
        $rollingIds = $toRoll->modelKeys();

        foreach ($toRoll as $night) {
            $this->releaseNight($night);
        }

        $keep = $nights
            ->reject(fn (PlanNight $night) => in_array($night->getKey(), $rollingIds, true))
            ->map(fn (PlanNight $night) => Pick::fromPlanNight($night))
            ->values()
            ->all();

        $picks = $this->picker()->pick(
            briefs: $toRoll->map(fn (PlanNight $night) => $this->briefFor($night))->values()->all(),
            pool: $this->pool(),
            random: SeededRandom::fromSeed($seed),
            keep: $keep,
        );

        $byDate = $toRoll->keyBy(fn (PlanNight $night) => $night->date->toDateString());

        foreach ($picks as $pick) {
            $this->applyPick($byDate->get($pick->night->date->toDateString()), $pick);
        }
    }

    // -----------------------------------------------------------------
    // Rolling and un-rolling a single night
    // -----------------------------------------------------------------

    /**
     * @param  array<int, string>  $exclude
     */
    private function rollOneNight(PlanNight $night, ?int $seed = null, array $exclude = []): Pick
    {
        $this->releaseNight($night);

        $pool = $this->pool();

        // The other nights of the same window still hold their meals, so this roll
        // cannot repeat one of them.
        foreach ($this->siblingNightsOf($night) as $sibling) {
            if (($key = Pick::fromPlanNight($sibling)->mealKey()) !== null) {
                $pool->markUsed($key);
            }
        }

        $pick = $this->nightPicker()->pick(
            $this->briefFor($night),
            $pool,
            SeededRandom::fromSeed($seed),
            $exclude,
        );

        $this->applyPick($night, $pick);

        return $pick;
    }

    /**
     * Keep the meal a night already has when it still fits, roll again when it
     * doesn't.
     *
     * A lock outranks the picking rules: the household wants this dinner on this
     * night, so an override that narrows the night does not take it away. The one
     * exception is an override that leaves **nobody** cooking. A lock says "keep
     * this dinner" and nobody says "there isn't one", and the household typed the
     * second one just now — so the meal goes. Otherwise the night would read
     * "Nobody cooking" against a dinner on MM-8's page, MM-9's post and MM-14's
     * dashboard, and a freezer portion would be held for a meal nobody will ever
     * eat. The lock itself stands: a settled night off is still settled.
     */
    private function repickIfNeeded(PlanNight $night): void
    {
        // The household may still record that they were away last Tuesday; the
        // dinner that was cooked stands either way.
        if ($this->hasPassed($night)) {
            return;
        }

        if ($night->is_locked && $night->cook->cooks()) {
            return;
        }

        if (! $night->isPlanned()) {
            return;
        }

        if ($night->hasMeal() && $this->mealStillFits($night)) {
            return;
        }

        $this->rollOneNight($night);
    }

    private function releaseIfMealNoLongerFits(PlanNight $night): void
    {
        if ($this->hasPassed($night)) {
            return;
        }

        if ($night->is_locked || ! $night->hasMeal() || $this->mealStillFits($night)) {
            return;
        }

        $this->releaseNight($night);
    }

    private function mealStillFits(PlanNight $night): bool
    {
        $option = $this->optionFor($night);

        return $option !== null
            && $this->nightPicker()->accepts($this->briefFor($night), $option);
    }

    private function applyPick(PlanNight $night, Pick $pick): void
    {
        if ($pick->selection === NightSelection::FreezerItem && $pick->freezerItemId !== null) {
            $this->reservePortionFor($night, $pick->freezerItemId);
        }

        $night->selection = $pick->selection;
        $night->recipe_id = $pick->recipeId;
        $night->freezer_item_id = $pick->freezerItemId;
        $night->pick_note = $pick->note;
        $night->pick_reason = $pick->reason?->toArray();
        $this->saveNight($night);
    }

    /**
     * Let go of whatever a night was holding, portion included.
     *
     * The portion only goes back if the night was really holding one: the night
     * carries its own reservation, so this is safe to call on a night that was
     * already released, on a row somebody wrote by hand, and on a night that
     * never had a freezer meal at all.
     */
    private function releaseNight(PlanNight $night): void
    {
        $night->releaseFreezerPortion();

        $night->selection = null;
        $night->recipe_id = null;
        $night->freezer_item_id = null;
        $night->pick_note = null;
        $night->pick_reason = null;
        $this->saveNight($night);
    }

    /**
     * Save a night whose meal has changed, and drop the loaded relations with it.
     *
     * Without this, a caller holding the model it passed in — a Livewire component
     * re-rolling a night (MM-8) — would read $night->recipe and get the recipe from
     * before the roll, because Eloquent keeps the relation it already loaded.
     */
    private function saveNight(PlanNight $night): void
    {
        $night->save();
        $night->unsetRelation('recipe');
        $night->unsetRelation('freezerItem');
    }

    /**
     * Put back what a night had, after a roll that found nothing better.
     *
     * @param  array{selection: NightSelection|null, recipe_id: int|null, freezer_item_id: int|null}  $previous
     */
    private function restore(PlanNight $night, array $previous, Pick $pick): void
    {
        if ($previous['selection'] === NightSelection::FreezerItem && $previous['freezer_item_id'] !== null) {
            $this->reservePortionFor($night, $previous['freezer_item_id']);
        }

        $night->selection = $previous['selection'];
        $night->recipe_id = $previous['recipe_id'];
        $night->freezer_item_id = $previous['freezer_item_id'];
        $night->pick_note = $pick->note;
        $night->pick_reason = $pick->reason?->toArray();
        $this->saveNight($night);
    }

    /**
     * Spend one portion of a freezer meal on a night, and record on the night
     * that it is holding it.
     *
     * The flag is what makes the release side safe, so the two are written
     * together: this is the only place a portion leaves the stash, and
     * PlanNight::releaseFreezerPortion() the only place one comes back.
     */
    private function reservePortionFor(PlanNight $night, int $freezerItemId): void
    {
        $item = FreezerItem::query()->lockForUpdate()->findOrFail($freezerItemId);

        if (! $item->isAvailable()) {
            throw PlanningException::noPortionsLeft($item);
        }

        $item->decrement('portions_left');
        $night->holds_freezer_portion = true;
    }

    // -----------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------

    private function pool(): MealPool
    {
        return MealPool::fromVault();
    }

    /**
     * The night as the picker sees it: the weekly schedule's cook and time, with
     * this date's override already applied, because that is what the row holds.
     */
    private function briefFor(PlanNight $night): NightBrief
    {
        return new NightBrief(
            date: $night->date,
            cook: $night->cook,
            timeBudget: $night->time_budget,
            overridesSchedule: $night->overrides_schedule,
            note: $night->override_note,
        );
    }

    private function optionFor(PlanNight $night): ?MealOption
    {
        return match ($night->selection) {
            NightSelection::Recipe => $night->recipe === null
                ? null
                : RecipeOption::fromModel($night->recipe),
            NightSelection::FreezerItem => $night->freezerItem === null
                ? null
                : FreezerOption::fromModel($night->freezerItem),
            default => null,
        };
    }

    private function mealNameOf(PlanNight $night): ?string
    {
        return $night->recipe?->name ?? $night->freezerItem?->name;
    }

    /**
     * @return Collection<int, PlanNight>
     */
    private function siblingNightsOf(PlanNight $night)
    {
        return PlanNight::query()
            ->where('plan_id', $night->plan_id)
            ->whereKeyNot($night->getKey())
            ->get();
    }

    /**
     * Has this night already gone by?
     *
     * Tonight has not: a night is only history once its date is behind today on
     * the kitchen clock, so the household can still change their mind at 8pm.
     */
    private function hasPassed(PlanNight $night): bool
    {
        return CalendarDate::of($night->date)->lessThan($this->today());
    }

    private function refuseIfPast(PlanNight $night): void
    {
        if ($this->hasPassed($night)) {
            throw PlanningException::pastNight($night);
        }
    }

    /**
     * The calendar date an instant falls on in the kitchen.
     *
     * For "now"-shaped arguments only: this converts, so a UTC timestamp late in
     * the evening still reports the day the household is living in.
     */
    private function dateOf(?DateTimeInterface $moment): CarbonImmutable
    {
        return $moment === null
            ? $this->today()
            : CalendarDate::in($moment, HouseholdSettings::timeZone());
    }
}
