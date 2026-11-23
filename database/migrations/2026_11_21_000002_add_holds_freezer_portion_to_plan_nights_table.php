<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record that a night is holding a freezer portion (MM-6).
 *
 * The freezer stash is the only thing the household counts (docs/decisions.md),
 * and planning a freezer meal spends one portion of it. Until now that spend was
 * only ever implied — "this night points at a freezer item, so a portion must
 * have come out" — which balances solely while every write goes through the
 * planner. A night written by a factory, a seeder or an import never took a
 * portion out, and releasing one of those *added* a portion that was never
 * frozen; releasing the same night twice added another.
 *
 * So the reservation says so itself. A release hands a portion back only when
 * this flag is set, and clears it as it does, which makes releasing twice,
 * releasing a hand-written row and releasing a night that never reserved all
 * harmless.
 *
 * Existing rows default to false: no portion can be proved to have been spent
 * for them, and inventing food is the worse error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_nights', function (Blueprint $table) {
            $table->boolean('holds_freezer_portion')->default(false)->after('freezer_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('plan_nights', function (Blueprint $table) {
            $table->dropColumn('holds_freezer_portion');
        });
    }
};
