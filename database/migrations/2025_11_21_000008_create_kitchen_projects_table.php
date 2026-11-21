<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring kitchen projects: stock, sauces, hummus, big batches of soup to
 * freeze (AppPlan.md, section 2).
 *
 * "Every X days" plus "last done on" is all MM-12 needs to decide a project is
 * due: due date is last_done_on + every_days, and a project that has never been
 * done (last_done_on null) is due now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // The "every X days" cadence.
            $table->unsignedSmallInteger('every_days');
            /*
             * What finishing it puts in the freezer. A foreign key rather than a
             * name so "add the portions" can't miss by a typo; null for projects
             * that stock the fridge instead (stock, sauces, hummus).
             */
            $table->foreignId('produces_freezer_item_id')
                ->nullable()
                ->constrained('freezer_items')
                ->nullOnDelete();
            // How many portions one run yields.
            $table->unsignedSmallInteger('produces_portions')->nullable();
            // Null means never done, which makes it due.
            $table->date('last_done_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_projects');
    }
};
