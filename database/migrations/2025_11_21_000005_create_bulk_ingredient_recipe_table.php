<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bulk ingredients a recipe draws on.
 *
 * Many-to-many: a recipe can lean on more than one bulk pack, and a bulk pack is
 * the whole point only because several recipes share it. The pairing in MM-11
 * walks this table to find two recipes that split one pack.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_ingredient_recipe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bulk_ingredient_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['recipe_id', 'bulk_ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_ingredient_recipe');
    }
};
