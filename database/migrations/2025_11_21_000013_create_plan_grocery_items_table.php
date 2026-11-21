<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The state of a plan's grocery checklist.
 *
 * MM-7 writes the rows — the window's ingredients merged, plus the kid backup
 * foods — and MM-8 flips `checked` as the household crosses things off at the
 * fridge. The list is stored rather than recomputed on each view because it is
 * shared: both phones have to see the same ticks, and a re-roll after shopping
 * must not silently rewrite the list someone is standing in the store with.
 *
 * Amounts are already merged here, so this is the list as shopped, not a view
 * over the ingredients table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_grocery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 8, 2)->nullable();
            $table->string('unit')->nullable();
            $table->string('store_section')->nullable();
            // Pantry basics render as the short "check you have" section.
            $table->boolean('pantry_basic')->default(false);
            $table->boolean('checked')->default(false);
            // When it was ticked; UTC, like every timestamp here.
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'store_section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_grocery_items');
    }
};
