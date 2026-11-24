<?php

use App\Grocery\MergeKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One thing on the list is one row, and one checkbox (MM-23).
 *
 * Nothing stopped the same line being stored twice: the table had only its
 * primary key and the non-unique `(plan_id, store_section)` index, and two
 * rebuilds racing each other — two phones re-rolling different nights, or a
 * re-roll racing `milly:groceries --rebuild` — could each insert the same line,
 * because under REPEATABLE READ neither one's read sees the other's row. Two
 * rows means MM-8 renders two checkboxes for one thing, and ticking one leaves
 * the other unticked.
 *
 * So the line's identity becomes a column and the database holds it to one row.
 * `merge_key` is `MergeKey::stored()` — the merge key MM-7 already folds two
 * needs on, hashed — which is why the index cannot refuse a line the planner
 * thinks is new, or accept one it thinks it already has. The obvious index on
 * `(plan_id, name, unit)` could not do this job: `unit` is nullable, MySQL counts
 * nulls as distinct in a unique index, and a need with no unit ("a splash of
 * soy") is an ordinary case rather than an edge.
 *
 * The index is added last, after every row has its key, because the household's
 * own database may already have duplicates from before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_grocery_items', function (Blueprint $table) {
            // Not nullable: a null key would be the loophole this closes. Rows
            // that already exist get an empty one, which storeTheKeys() fills in
            // below while there is still no index to offend.
            $table->char('merge_key', MergeKey::STORED_WIDTH)
                ->charset('ascii')
                ->collation('ascii_bin')
                ->after('unit');
        });

        $this->storeTheKeys();

        Schema::table('plan_grocery_items', function (Blueprint $table) {
            $table->unique(['plan_id', 'merge_key']);
        });
    }

    public function down(): void
    {
        Schema::table('plan_grocery_items', function (Blueprint $table) {
            $table->dropUnique(['plan_id', 'merge_key']);
            $table->dropColumn('merge_key');
        });
    }

    /**
     * Give every stored row its key, and collapse any window that already holds
     * one line twice.
     *
     * **The ticked row survives**, as it does when a rebuild finds the same thing
     * (`GroceryListBuilder::oneRowOf()`): that tick is somebody's work at the
     * fridge, and a migration is the last place to throw it away. Otherwise the
     * oldest row stays, because that is the one a household has been looking at.
     * What the keeper says it needs is left alone — the next rebuild settles the
     * amount and the meals — and the collapse is logged, because a list quietly
     * losing a row is how this went unnoticed in the first place.
     *
     * Public, and raw queries rather than the model, so the test can run exactly
     * this against rows that have a column but no index yet
     * (tests/Feature/Grocery/GroceryDuplicateLinesTest.php).
     */
    public function storeTheKeys(): void
    {
        $keepers = [];
        $duplicates = [];

        $rows = DB::table('plan_grocery_items')
            ->select('id', 'plan_id', 'name', 'unit', 'checked')
            // The ticked row of a window's line first, then the oldest.
            ->orderBy('plan_id')
            ->orderByDesc('checked')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $key = MergeKey::stored(MergeKey::of($row->name, $row->unit));

            if (isset($keepers[$row->plan_id][$key])) {
                $duplicates[] = [
                    'id' => $row->id,
                    'plan_id' => $row->plan_id,
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'kept' => $keepers[$row->plan_id][$key],
                ];

                continue;
            }

            $keepers[$row->plan_id][$key] = $row->id;
        }

        // Decided first, then written, and the duplicates go before any key is
        // stored: a row about to be deleted must not be in the way of the key its
        // keeper is getting. That also makes running this twice a no-op.
        if ($duplicates !== []) {
            DB::table('plan_grocery_items')->whereIn('id', array_column($duplicates, 'id'))->delete();

            Log::warning(
                'A grocery line was stored more than once before MM-23; the duplicates were removed and the ticked row kept.',
                ['duplicates' => $duplicates],
            );
        }

        foreach ($keepers as $keys) {
            foreach ($keys as $key => $id) {
                DB::table('plan_grocery_items')->where('id', $id)->update(['merge_key' => $key]);
            }
        }
    }
};
