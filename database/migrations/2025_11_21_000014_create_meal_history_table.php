<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we actually cooked, and when.
 *
 * This is what variety is measured against: no recipe twice in one window, and
 * favour recipes that haven't been cooked recently (docs/decisions.md). Separate
 * from plan_nights because a plan records the intention and this records the
 * outcome — nights get swapped, and a plan can be re-rolled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_history', function (Blueprint $table) {
            $table->id();
            $table->date('cooked_on');
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // The same recipe is only cooked once on a given day.
            $table->unique(['cooked_on', 'recipe_id']);
            // "How long since we last made chili?" walks this backwards.
            $table->index(['recipe_id', 'cooked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_history');
    }
};
