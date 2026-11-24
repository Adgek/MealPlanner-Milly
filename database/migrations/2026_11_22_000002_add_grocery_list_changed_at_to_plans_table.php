<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this plan's grocery list last changed (MM-7).
 *
 * The list is made again whenever the plan changes, and the household has to be
 * able to tell a list that is simply current from one that moved under them after
 * they had already shopped. This column is that fact: it is stamped only when a
 * rebuild actually added, removed or altered a line, so comparing it with the
 * shop's own moment (`Plan::shoppingMoment()`) answers "did the plan change after
 * we shopped" — the warning MM-8 owes the household.
 *
 * Null means the list has never been made. An instant, so UTC in the column;
 * render it in the household's zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->timestamp('grocery_list_changed_at')->nullable()->after('last_night');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('grocery_list_changed_at');
        });
    }
};
