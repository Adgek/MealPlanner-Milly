<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retiring a recipe, so removing one from the vault cannot destroy the record of
 * having cooked it (MM-15).
 *
 * `meal_history.recipe_id` is `cascadeOnDelete` and `plan_nights.recipe_id` is
 * `nullOnDelete`, so a plain DELETE takes every record of the household ever
 * having made this recipe with it — the only input the variety weighting has
 * (MM-6) — and leaves any night that had it as `selection = 'recipe'` with no
 * recipe, which MM-8, MM-9 and MM-14 would all render as a blank dinner.
 *
 * A retired recipe is still a row, so none of that happens: history keeps
 * pointing at it, a night that has gone by still says what was eaten, and the
 * picker simply stops choosing it (MealPool::fromVault()). Retiring is
 * reversible; deleting is only offered for a recipe nothing points at.
 *
 * Deliberately not Laravel's SoftDeletes: that adds a global scope, so
 * `$night->recipe` and `$entry->recipe` would come back null the moment a recipe
 * was retired — exactly the blank dinner this is here to prevent. The column is
 * read explicitly instead, through the `inTheVault` and `retired` scopes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            // An instant, not a date: it records when somebody pressed the
            // button, so it is stored in UTC like every other timestamp.
            $table->timestamp('retired_at')->nullable()->after('kid_off_ramp');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('retired_at');
        });
    }
};
