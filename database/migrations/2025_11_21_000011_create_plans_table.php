<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One plan per shopping window.
 *
 * A window is the stretch a single shop has to cover: with the default schedule,
 * shopping on Sunday covers Mon-Wed and shopping on Wednesday covers Thu-Sun
 * (AppPlan.md). first_night and last_night are stored rather than derived, so a
 * plan that is already out keeps the window it was built for even if the
 * household later changes its shopping days.
 *
 * Dates here are calendar dates in the household time zone, not instants: a
 * DATE column has no time and no offset, so "Wednesday" cannot drift to Tuesday
 * the way a UTC timestamp would. See docs/decisions.md on time zones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            // Unique: one plan per shopping window, and the shop identifies the window.
            $table->date('shopping_date')->unique();
            $table->date('first_night');
            $table->date('last_night');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
