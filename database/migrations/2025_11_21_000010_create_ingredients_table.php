<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a recipe or a kitchen project needs from the store.
 *
 * One polymorphic table rather than two near-identical ones, because the grocery
 * list (MM-7) combines both sources — the window's meals plus any kitchen project
 * that is due — and merging them is a single query over one table instead of a
 * union over two.
 *
 * Nothing here tracks the pantry: docs/decisions.md says the list shows everything
 * the window needs and the household crosses items off at the fridge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            // ingredientable_type + ingredientable_id: a Recipe or a KitchenProject.
            $table->morphs('ingredientable');
            $table->string('name');
            /*
             * Amount and unit are separate so the list can merge them:
             * "1 lb + 1 lb ground beef" becomes "2 lb" (docs/decisions.md).
             * Both nullable, for the ingredients nobody measures ("a splash of soy").
             */
            $table->decimal('quantity', 8, 2)->nullable();
            $table->string('unit')->nullable();
            /*
             * Where it lives in the store, so the list is walkable in one pass.
             * A free-text string, not an enum: the sections depend on the
             * household's store, which makes them data rather than code.
             */
            $table->string('store_section')->nullable();
            // Pantry basics go in a short "check you have" section of the list.
            $table->boolean('pantry_basic')->default(false);
            $table->timestamps();

            // MM-7 merges identical ingredients across the window by name.
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
