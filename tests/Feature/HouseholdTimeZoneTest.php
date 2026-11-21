<?php

use App\Models\Setting;
use App\Settings\HouseholdSettings;
use Illuminate\Support\Facades\DB;

/*
 * The app keeps two clocks apart on purpose (MM-3, and docs/decisions.md):
 *
 *   - it computes and stores in UTC, so an instant in the database is absolute;
 *   - the household time zone is applied when a page displays a time, and when
 *     the scheduler decides a task is due.
 *
 * Getting this wrong is quiet: reminders drift by the UTC offset and nobody sees
 * why. These tests pin down both halves.
 */

it('computes in UTC, so stored instants are absolute', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});

it('writes timestamps to MySQL in UTC', function () {
    $setting = Setting::write('a_test_setting', 'value');

    $stored = DB::table('settings')->where('id', $setting->id)->value('created_at');

    // What is in the column should be the UTC instant, not a local rendering of it.
    expect($stored)->toBe($setting->created_at->utc()->format('Y-m-d H:i:s'));
});

it('keeps the household time zone in Settings, where the household can change it', function () {
    expect(HouseholdSettings::timeZone())->toBe('America/Toronto');

    HouseholdSettings::set(HouseholdSettings::KEY_TIME_ZONE, 'Europe/Berlin');

    expect(HouseholdSettings::timeZone())->toBe('Europe/Berlin');
});

it('fires the scheduler on the household clock, not on UTC', function () {
    // Laravel reads app.schedule_timezone when it builds the schedule. Without
    // this, "3:00 pm" in MM-13 would mean 3:00 pm UTC — mid-morning in the kitchen.
    expect(config('app.schedule_timezone'))->toBe(HouseholdSettings::timeZone());
});

it('shows a page time in the household zone rather than UTC', function () {
    $this->get('/plan')
        ->assertOk()
        ->assertSee(HouseholdSettings::timeZone())
        ->assertDontSee('Kitchen clock (UTC)');
});
