<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which meals of the window want this line (MM-7).
 *
 * "Show which meals use each item" is part of the list, not a view over it: the
 * stored checklist is the list as shopped (MM-3), so it has to be able to say why
 * a line is on it without going back to the plan. Reading the meals off the plan
 * instead would re-label a line the household already ticked whenever a night was
 * re-rolled — and MM-9's post would need the plan joined in to render one line of
 * text.
 *
 * A JSON list of meal names, in night order, because a merged line can serve more
 * than one dinner: "Ground beef — 2 lb (Beef Bulgogi, White Boy Tacos)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_grocery_items', function (Blueprint $table) {
            $table->json('meals')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('plan_grocery_items', function (Blueprint $table) {
            $table->dropColumn('meals');
        });
    }
};
