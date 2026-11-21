<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's own session store, because SESSION_DRIVER is `database`.
 *
 * There is no users table and no sign-in: MM-1 chose no login because the app only
 * runs on the home network. Sessions still exist so Blade and Livewire have CSRF
 * protection and flash messages.
 *
 * No household tables live here yet — MM-3 adds the recipe vault and the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            // Laravel's DatabaseSessionHandler writes this column on every request
            // whether or not authentication is in use, so it has to exist. It stays
            // a plain nullable column with no foreign key, because there is no
            // users table to point at.
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
