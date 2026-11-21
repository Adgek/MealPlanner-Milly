<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upstream prep: the things that have to happen before the night of the meal,
 * like soaking beans before Wednesday's chili (AppPlan.md).
 *
 * lead_time_hours is what makes a reminder possible: MM-13 subtracts it from
 * dinner time on the night the recipe is planned, so the nudge goes out when the
 * work actually has to happen rather than when someone remembers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_prep_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // What to do: "soak the beans".
            $table->string('description');
            /*
             * How far ahead of dinner it happens, in hours:
             *   0   the night of, as part of cooking
             *   24  the night before
             *   48  two nights before
             * Hours rather than days so a two-hour marinade fits the same column.
             */
            $table->unsignedSmallInteger('lead_time_hours')->default(0);
            // Order within the recipe; prep is a sequence.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['recipe_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_prep_steps');
    }
};
