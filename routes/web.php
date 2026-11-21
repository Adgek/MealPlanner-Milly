<?php

use App\Livewire\Plan;
use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| There is no authentication: MM-1 chose no login because the app is only
| reachable on the home network. Do not expose these routes to the internet.
|
| Livewire components are routed directly, which renders them as full pages
| inside resources/views/layouts/app.blade.php.
|
*/

Route::redirect('/', '/plan')->name('home');

Route::get('/plan', Plan::class)->name('plan');
Route::get('/settings', Settings::class)->name('settings');
