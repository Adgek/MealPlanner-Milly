<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Household values live here, not in code (AGENTS.md).
 *
 * One row per setting, with the value as JSON so a setting can be a string
 * ("18:00"), a number (2) or a structure (the two shopping windows) without a
 * migration per shape. The starting defaults are in docs/decisions.md and are
 * installed by SettingsSeeder; the Settings page (MM-4) edits them.
 *
 * The weekly cook schedule is the one household value that is not a row here:
 * it is seven rows in cook_schedule_nights, because each night is edited and
 * read on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            // Nullable so "deliberately unset" is expressible; a missing row and a
            // null value both fall back to the default in HouseholdSettings.
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
