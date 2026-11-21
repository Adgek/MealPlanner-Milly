<?php

namespace App\Providers;

use App\Settings\HouseholdSettings;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->scheduleInTheHouseholdTimeZone();
    }

    /**
     * Point the scheduler at the household's own time zone.
     *
     * The app computes and stores in UTC (config/app.php), so without this a task
     * set for 3:00 pm would fire at 3:00 pm UTC — mid-morning in the kitchen.
     * Laravel reads 'app.schedule_timezone' when it builds the schedule, and
     * config/app.php seeds it from HOUSEHOLD_TIMEZONE; here we replace that with
     * the household's 'time_zone' setting, so changing the time zone on the
     * Settings page (MM-4) moves the reminders with it.
     */
    private function scheduleInTheHouseholdTimeZone(): void
    {
        // Only the scheduler and artisan evaluate a schedule. Skipping this on web
        // requests keeps a settings query off every page load.
        if (! $this->app->runningInConsole()) {
            return;
        }

        try {
            $timeZone = HouseholdSettings::timeZone();
        } catch (Throwable) {
            // No settings table yet: a fresh clone mid-`migrate`, or a dropped
            // database. The HOUSEHOLD_TIMEZONE fallback already covers it.
            return;
        }

        config(['app.schedule_timezone' => $timeZone]);
    }
}
