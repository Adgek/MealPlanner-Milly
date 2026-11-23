<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why nothing fitted a night, as facts rather than as a sentence (MM-6).
 *
 * `pick_note` carries what the planner has to say about a night in its own words
 * ("Kept Chili: nothing else fits."). The reason the *picker* ran out of options
 * cannot be stored that way, because saying it out loud means naming the cook,
 * and what the household calls its two cooks is a setting they can change at any
 * time (MM-4). A sentence written at pick time would still read "Partner" after
 * she had been renamed to Milly, and nothing could go back and repair it.
 *
 * So the counts are stored and the sentence is rendered on the way out, by
 * whatever is showing it to a person. See App\Planning\NothingFitsReason.
 *
 * JSON rather than eleven columns: these are one value, read and written
 * together, and never queried on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_nights', function (Blueprint $table) {
            $table->json('pick_reason')->nullable()->after('pick_note');
        });
    }

    public function down(): void
    {
        Schema::table('plan_nights', function (Blueprint $table) {
            $table->dropColumn('pick_reason');
        });
    }
};
