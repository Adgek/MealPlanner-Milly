<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared ingredients a bulk pack gets split across two meals — ground beef
 * being the example in AppPlan.md.
 *
 * fridge_life_days is how long the raw second half keeps, counted from the
 * shopping day. The pairing rule in MM-11 needs it to decide whether the second
 * meal still fits in the window or the plan has to say "freeze the second half".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Null means "no specific life recorded": fall back to the
            // raw_split_fridge_life_days setting (2 days by default).
            $table->unsignedSmallInteger('fridge_life_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_ingredients');
    }
};
