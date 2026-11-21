<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recipe vault (AppPlan.md, section 1).
 *
 * Ingredients hang off this table polymorphically, prep steps and bulk
 * ingredients in their own tables, so a recipe row stays the small set of
 * facts the picker (MM-6) filters on: who may cook it and how long it takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            // Unique so the import in MM-5 can run twice without duplicating a recipe.
            $table->string('name')->unique();
            // App\Enums\CookableBy: me, partner or both.
            $table->string('cookable_by');
            // App\Enums\EffortTier: 15-min chaos, 30-min workhorse or 60-min+ project.
            $table->string('effort_tier');
            // How to adapt this meal for the kids. Nullable: not every meal needs one.
            $table->text('kid_off_ramp')->nullable();
            $table->timestamps();

            // The picker's hot path: "recipes this person can cook in this much time".
            $table->index(['cookable_by', 'effort_tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
