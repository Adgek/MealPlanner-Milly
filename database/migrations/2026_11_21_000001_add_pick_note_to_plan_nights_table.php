<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the picker landed where it did (MM-6).
 *
 * Today this carries one thing: the reason nothing fitted a night. "Nothing fits"
 * on its own is useless to the household — the fix is either a new quick recipe,
 * a different cook that night or a restocked freezer, and only the reason says
 * which. The rest of the plan still goes ahead, so the night needs somewhere to
 * keep its explanation.
 *
 * Deliberately not override_note: that is the household's own reason for changing
 * a night ("out for dinner"), written by a person. This is the picker talking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_nights', function (Blueprint $table) {
            $table->string('pick_note', 500)->nullable()->after('override_note');
        });
    }

    public function down(): void
    {
        Schema::table('plan_nights', function (Blueprint $table) {
            $table->dropColumn('pick_note');
        });
    }
};
