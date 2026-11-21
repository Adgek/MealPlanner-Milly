<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One night of a plan: who is cooking, how long they have, and what they are making.
 *
 * cook and time_budget are copied from the weekly cook schedule when the plan is
 * built, then may be changed for this one date — travel, leftovers, eating out
 * (docs/decisions.md). Copying rather than joining is deliberate: editing the
 * household's normal week must not rewrite a plan that is already out.
 *
 * is_locked is what makes re-rolling safe: until you shop you can re-roll, lock
 * or swap any night, and a locked night survives the next roll untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // The values in force for this night. App\Enums\CookAssignment / EffortTier.
            $table->string('cook');
            $table->string('time_budget');

            /*
             * The one-off override. The flag records that this night deliberately
             * departs from the weekly schedule — which cannot be re-derived later,
             * since the schedule itself may have changed since — and the note says
             * why, for the plan page and the Slack post ("out for dinner").
             */
            $table->boolean('overrides_schedule')->default(false);
            $table->string('override_note')->nullable();

            /*
             * What was chosen. App\Enums\NightSelection, nullable until the picker
             * has run. Exactly one of the two keys is set, matching the selection:
             * a recipe, a freezer meal, or neither for a night off.
             */
            $table->string('selection')->nullable();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('freezer_item_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            // One row per date per plan.
            $table->unique(['plan_id', 'date']);
            // "What are we eating tonight?" for the dashboard and the daily reminder.
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_nights');
    }
};
