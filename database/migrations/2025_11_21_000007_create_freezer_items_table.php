<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The 80%-prepped stash (AppPlan.md, section 2): what is in the freezer and how
 * many portions are left.
 *
 * docs/decisions.md makes this the only thing the app counts. A finished kitchen
 * project adds portions, a planned freezer meal uses one, and MM-12 warns when
 * the count drops to the low_freezer_stash_portions setting.
 *
 * A row is both the catalogue entry and the count, so portions_left = 0 is normal:
 * it means "we know this meal, we have none right now", and the thaw lead time and
 * kid note stay attached for the next batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freezer_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('portions_left')->default(0);
            /*
             * How long it needs out of the freezer before dinner, in hours. The
             * thaw reminder goes out the night before (docs/decisions.md), which
             * this number lets MM-13 compute rather than assume.
             */
            $table->unsignedSmallInteger('thaw_lead_time_hours')->default(0);
            $table->text('kid_off_ramp')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freezer_items');
    }
};
