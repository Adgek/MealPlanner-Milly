<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kid off-ramp inventory (AppPlan.md, section 3): the guaranteed-safe backup
 * items to keep stocked alongside the regular groceries.
 *
 * Amount is split into quantity and unit, the same shape as ingredients, so the
 * grocery list (MM-7) can add these to the list and merge them with anything a
 * recipe already asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kid_backup_foods', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('quantity', 8, 2)->nullable();
            $table->string('unit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kid_backup_foods');
    }
};
