<?php

/*
 * The scheduler container runs in the household time zone, so reminders land at
 * the right local time (MM-9, MM-13). That only holds if the app's own time zone
 * comes from APP_TIMEZONE rather than Laravel's hard-coded 'UTC'.
 */

it('reads the app time zone from APP_TIMEZONE', function () {
    expect(config('app.timezone'))->toBe(env('APP_TIMEZONE', 'UTC'));
});

it('keeps php and laravel on the same clock', function () {
    expect(date_default_timezone_get())->toBe(config('app.timezone'));
});

it('is not left on UTC by accident', function () {
    // The household is in America/Toronto (docs/decisions.md). This guards the
    // wiring, not the value: Settings (MM-4) owns the household's own choices.
    expect(env('APP_TIMEZONE'))->not->toBeEmpty();
});
