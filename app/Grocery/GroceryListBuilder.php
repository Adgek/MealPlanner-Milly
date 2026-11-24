<?php

namespace App\Grocery;

use App\Models\Ingredient;
use App\Models\KidBackupFood;
use App\Models\Plan;
use App\Models\PlanGroceryItem;
use App\Models\Recipe;
use App\Planning\MealPlanner;
use App\Planning\Pick;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The grocery list, as the rest of the app uses it.
 *
 * Everything other tickets need is here, and none of the merging rules are: this
 * class reads the plan through MealPlanner, loads the ingredients, keeps the
 * stored checklist in step and hands back plain values, while GroceryBasket,
 * GroceryMerge and GroceryLine decide what the list says from plain values. That
 * split is what lets the merge be unit-tested without a database (AGENTS.md).
 *
 * What the other tickets call:
 *
 *   currentFor()  the list to show somebody — MM-8's page, MM-14's dashboard
 *   textFor()     the same list as plain text — MM-9's post
 *   listFor()     the stored list exactly as stored, stale or not
 *   isStale()     does the window ask for something the stored list has not got?
 *   rebuild()     make the list again, and say what moved
 *   previewFor()  what the window asks for, without storing any of it
 *
 * **The list is stored, not recomputed** (MM-3): both phones have to see the same
 * ticks, and a re-roll must not rewrite the list somebody is standing in the shop
 * with. So rebuild() reconciles rather than replacing — see its own docblock for
 * what happens to a tick.
 */
final class GroceryListBuilder
{
    /**
     * Both collaborators are optional and read from Settings when missing, so
     * `new GroceryListBuilder` is the household's own configuration and a test
     * can hand in a different store order or a different plan source.
     */
    public function __construct(
        private readonly ?MealPlanner $planner = null,
        private readonly ?GroceryMerge $merge = null,
    ) {}

    // -----------------------------------------------------------------
    // Reading the list
    // -----------------------------------------------------------------

    /**
     * The list to put in front of somebody: the stored checklist, made again
     * first if it has fallen behind what the window asks for.
     *
     * **This is what a page or a post should call.** The planner makes the list
     * again whenever a dinner changes (MealPlanner), but editing a recipe's
     * ingredients or the kid off-ramp inventory touches no plan at all — and MM-15
     * is adding screens for exactly that. A read that corrected nothing would show
     * a list quietly older than the edit, with nothing to warn about either,
     * because nothing stamped the plan.
     *
     * It writes, then, but only when the list really is behind, and the rebuild
     * keeps every tick that still means something.
     */
    public function currentFor(Plan $plan): GroceryList
    {
        return $this->isStale($plan)
            ? $this->rebuild($plan)->list
            : $this->listFor($plan);
    }

    /**
     * Does the window ask for something other than what is stored?
     *
     * Ticks are not part of the question — only what there is to buy, in what
     * amount, in which section. Writes nothing, for a caller that would rather
     * show a warning than rewrite the list (MM-8).
     *
     * False for a window whose last night has gone by: nothing can change it any
     * more, so "behind" is not a thing it can be.
     */
    public function isStale(Plan $plan): bool
    {
        if ($plan->hasPassed()) {
            return false;
        }

        return $this->rowsOf($this->previewFor($plan)) !== $this->rowsOf($this->listFor($plan));
    }

    /**
     * The stored checklist for a plan, exactly as stored: grouped by store
     * section, pantry basics last, with each line's row id and tick.
     *
     * The list as shopped, stale or not — it is what rebuild() last wrote and is
     * never derived from the plan on the way out. Use currentFor() to show it to
     * somebody.
     */
    public function listFor(Plan $plan): GroceryList
    {
        return $this->merge()->group(
            $this->storedRows($plan)
                ->map(fn (PlanGroceryItem $item) => GroceryLine::fromStoredItem($item))
                ->all(),
        );
    }

    /**
     * The stored list as plain text, for the household chat (MM-9) or a terminal.
     *
     * The dinners come with it, with the cooks under the household's own names.
     */
    public function textFor(Plan $plan): string
    {
        return GroceryListText::post(
            $this->currentFor($plan),
            $plan->window(),
            $this->picksFor($plan),
            HouseholdSettings::cookLabel(...),
        );
    }

    /**
     * What this window asks for as the plan stands, storing nothing.
     *
     * The merge on its own: no ids, no ticks. Useful for a dry run and for seeing
     * what a rebuild would do.
     */
    public function previewFor(Plan $plan): GroceryList
    {
        return $this->merge()->merge($this->needsFor($plan));
    }

    // -----------------------------------------------------------------
    // Making it again
    // -----------------------------------------------------------------

    /**
     * Make the list again from the plan as it stands, and say what moved.
     *
     * "Make the list again whenever the plan changes" has to live with "the
     * checklist is stored": somebody may be holding the old list with ticks on
     * it. So this reconciles the stored rows against what the window now asks
     * for, line by line (docs/decisions.md, MM-7):
     *
     *   - a line that is **still needed keeps its row and its tick**, even when
     *     the meals behind it changed. "We have this" does not stop being true
     *     because Tuesday's dinner did;
     *   - unless the plan now **asks for more of it** — then the tick is dropped,
     *     because "we have enough" was true of the old amount;
     *   - a line **nothing wants any more goes**, ticked or not. It is not part of
     *     this shop;
     *   - a line **nothing had asked for before is added, unticked**, so it stands
     *     out as the thing the re-roll means you still have to buy.
     *
     * The plan is stamped (`grocery_list_changed_at`) only when something really
     * moved, which is what lets MM-8 warn that the plan changed after the
     * household had already shopped.
     *
     * A window whose last night has gone by is left exactly as it was: the
     * planner will not change a night that has passed either, so there is nothing
     * the list could be out of step with, and rewriting it would only damage the
     * record of what was actually bought.
     *
     * **Two of these can run at once** — two phones re-rolling different nights,
     * or a re-roll racing `milly:groceries --rebuild` — so the stored rows are
     * read for update and the writes go through the line's key (MM-23). One thing
     * stays one row, and one checkbox.
     */
    public function rebuild(Plan $plan): GroceryListUpdate
    {
        if ($plan->hasPassed()) {
            return GroceryListUpdate::leftAlone(
                $this->listFor($plan),
                'That window is over: its list stands as it was shopped.',
            );
        }

        return DB::transaction(function () use ($plan) {
            $wanted = $this->previewFor($plan);
            $rows = $this->storedRowsByMergeKey($plan);

            $added = [];
            $removed = [];
            $unticked = [];
            $amended = false;

            foreach ($wanted->lines() as $line) {
                $stored = $rows->pull($line->mergeKey());

                if ($stored === null) {
                    $this->store($plan, $line);
                    $added[] = $line;

                    continue;
                }

                $row = $this->oneRowOf($stored);
                $was = GroceryLine::fromStoredItem($row);
                $row->fill($line->toRow());

                if ($row->checked && $line->asksForMoreThan($was)) {
                    $row->checked = false;
                    $row->checked_at = null;
                    $unticked[] = $line;
                }

                // Only a line that really reads differently counts as a change:
                // the stamp has to mean "the list moved", not "somebody looked".
                // A key being put right is not the list moving either — nothing
                // the household reads changed (MM-23).
                $amended = $amended || array_diff(
                    array_keys($row->getDirty()),
                    ['checked', 'checked_at', 'merge_key'],
                ) !== [];

                $row->save();
            }

            // Whatever is left is on the old list and in none of the window's
            // meals.
            foreach ($rows->flatten() as $row) {
                $removed[] = GroceryLine::fromStoredItem($row);
                $row->delete();
            }

            $update = GroceryListUpdate::of(
                $this->listFor($plan),
                $added,
                $removed,
                $unticked,
                $amended,
            );

            if ($update->changedAnything()) {
                $plan->recordGroceryListChange();
            }

            return $update;
        });
    }

    // -----------------------------------------------------------------
    // Settings-backed collaborators
    // -----------------------------------------------------------------

    public function planner(): MealPlanner
    {
        return $this->planner ?? new MealPlanner;
    }

    public function merge(): GroceryMerge
    {
        return $this->merge ?? GroceryMerge::fromSettings();
    }

    // -----------------------------------------------------------------
    // Reading the window
    // -----------------------------------------------------------------

    /**
     * Everything the window asks for, in night order, with the kid backup foods
     * on the end.
     *
     * @return array<int, IngredientNeed>
     */
    private function needsFor(Plan $plan): array
    {
        $picks = $this->picksFor($plan);

        return GroceryBasket::needs(
            $picks,
            $this->needsByRecipe(GroceryBasket::recipeIds($picks)),
            $this->kidBackupNeeds(),
        );
    }

    /**
     * The plan as a list of picks, through MealPlanner rather than re-derived:
     * one door to what a window was planned as (docs/decisions.md, MM-6).
     *
     * @return array<int, Pick>
     */
    private function picksFor(Plan $plan): array
    {
        return $this->planner()->picksFor($plan);
    }

    /**
     * The ingredients of the recipes the window chose, keyed by recipe id.
     *
     * Recipes only. Ingredients are one polymorphic table shared with kitchen
     * projects (MM-3) precisely so that MM-12 can add a due project's ingredients
     * to the same merge — but a project is not a dinner, and MM-12 is the ticket
     * that decides when one is due.
     *
     * @param  array<int, int>  $recipeIds
     * @return array<int, array<int, IngredientNeed>>
     */
    private function needsByRecipe(array $recipeIds): array
    {
        if ($recipeIds === []) {
            return [];
        }

        return Ingredient::query()
            ->where('ingredientable_type', (new Recipe)->getMorphClass())
            ->whereIn('ingredientable_id', $recipeIds)
            ->orderBy('id')
            ->get()
            ->groupBy('ingredientable_id')
            ->map(fn ($ingredients) => $ingredients
                ->map(fn (Ingredient $ingredient) => IngredientNeed::fromIngredient($ingredient))
                ->all())
            ->all();
    }

    /**
     * The kid off-ramp inventory, which goes on every list whatever is cooking
     * (AppPlan.md, section 3).
     *
     * @return array<int, IngredientNeed>
     */
    private function kidBackupNeeds(): array
    {
        return KidBackupFood::query()
            ->orderBy('name')
            ->get()
            ->map(fn (KidBackupFood $food) => IngredientNeed::fromKidBackupFood($food))
            ->all();
    }

    /**
     * A list as the rows it would be stored as, keyed and ordered by merge key,
     * so two lists can be compared for "is there anything different to buy".
     *
     * @return array<string, array<string, mixed>>
     */
    private function rowsOf(GroceryList $list): array
    {
        $rows = [];

        foreach ($list->lines() as $line) {
            $rows[$line->mergeKey()] = $line->toRow();
        }

        ksort($rows);

        return $rows;
    }

    /**
     * Store a line the window asks for and the stored list has not got.
     *
     * An upsert on the line's key rather than an insert, because "the list has
     * not got it" was read a moment ago: another rebuild may have stored it
     * since, and under REPEATABLE READ this transaction's own reads cannot see
     * that it did. The unique index would then refuse the insert, and the
     * household would get a duplicate-key error for having two phones. This
     * updates that row instead (MM-23).
     *
     * It writes exactly the columns a line owns (`GroceryLine::toRow()`), so a
     * tick the other rebuild's row already carries is left alone, and so is the
     * `created_at` that says when the line first appeared. Attributes come off an
     * unsaved model, so the casts stay in one place even though an upsert goes
     * round them.
     */
    private function store(Plan $plan, GroceryLine $line): void
    {
        $row = $line->toRow();
        $attributes = $plan->groceryItems()->make($row)->getAttributes();

        $plan->groceryItems()->upsert([$attributes], ['plan_id', 'merge_key'], array_keys($row));
    }

    /**
     * The stored rows, oldest first, so the line a household has been ticking is
     * the one the reconcile recognises.
     *
     * @return Collection<int, PlanGroceryItem>
     */
    private function storedRows(Plan $plan, bool $forUpdate = false): Collection
    {
        return $plan->groceryItems()
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();
    }

    /**
     * The stored rows grouped the way the merge keys a line, read for update.
     *
     * **For update**, which is what makes a rebuild right while another one is
     * running (MM-23). A plain read inside a transaction sees the list as it was
     * when the transaction started, so a rebuild could reconcile against rows
     * that are no longer there and lines that are. This reads what is committed
     * now, and holds the window's rows — including the gap where they would be,
     * so a list that is still empty is covered too — until the rebuild commits.
     * The second of two rebuilds waits and then sees the first one's work.
     *
     * Grouped rather than keyed, because two rows could still carry one line: one
     * written before MM-23 added the index, or by hand. Keying would silently
     * drop one of them from the reconcile and leave it on the list forever.
     *
     * @return Collection<string, Collection<int, PlanGroceryItem>>
     */
    private function storedRowsByMergeKey(Plan $plan): Collection
    {
        return $this->storedRows($plan, forUpdate: true)
            ->groupBy(fn (PlanGroceryItem $item) => GroceryLine::fromStoredItem($item)->mergeKey());
    }

    /**
     * The one row that is going to carry a line, with any others deleted.
     *
     * The ticked one wins, because that tick is the household's work; otherwise
     * the oldest. The rows that go are not reported as removed: they were never a
     * line of their own, and treating a tidy-up as a change would stamp the plan
     * and have MM-8 warn about a list that reads exactly as it did.
     *
     * @param  Collection<int, PlanGroceryItem>  $stored
     */
    private function oneRowOf(Collection $stored): PlanGroceryItem
    {
        $keep = $stored->firstWhere('checked', true) ?? $stored->first();

        $stored->reject(fn (PlanGroceryItem $row) => $row->is($keep))
            ->each(fn (PlanGroceryItem $row) => $row->delete());

        return $keep;
    }
}
