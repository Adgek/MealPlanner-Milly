<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same retirement for the freezer stash (MM-15).
 *
 * Deleting a stash item is worse than deleting a recipe. `plan_nights`
 * `freezer_item_id` is `nullOnDelete`, and a planned freezer meal also holds a
 * portion out of the stash (`plan_nights.holds_freezer_portion`, MM-6) — so a
 * delete leaves a reservation pointing at nothing, and a past night loses the
 * record of what the household actually ate out of the freezer.
 *
 * Retiring keeps the row and its portion count, and takes the item out of the
 * picker's stash instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freezer_items', function (Blueprint $table) {
            $table->timestamp('retired_at')->nullable()->after('kid_off_ramp');
        });
    }

    public function down(): void
    {
        Schema::table('freezer_items', function (Blueprint $table) {
            $table->dropColumn('retired_at');
        });
    }
};
