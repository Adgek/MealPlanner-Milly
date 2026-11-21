<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The household's normal week: for each night, who cooks and how much time there is.
 *
 * Exactly seven rows, one per weekday, enforced by the unique index. A plan night
 * copies its cook and time from here and can then be overridden one-off, so
 * changing the schedule never rewrites a plan that is already out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cook_schedule_nights', function (Blueprint $table) {
            $table->id();
            // App\Enums\Weekday
            $table->string('weekday')->unique();
            // App\Enums\CookAssignment: me, partner, together or nobody.
            $table->string('cook');
            // App\Enums\EffortTier: how much time this night has (15, 30 or 60+).
            $table->string('time_budget');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cook_schedule_nights');
    }
};
