<?php

namespace Database\Seeders;

use App\Enums\Weekday;
use App\Models\CookScheduleNight;
use App\Models\Setting;
use App\Settings\HouseholdSettings;
use Illuminate\Database\Seeder;

/**
 * The starting settings from docs/decisions.md, plus the starting weekly cook
 * schedule.
 *
 * This runs on every boot (the container entrypoint calls it after migrating),
 * which is why it only ever *adds*. A setting the household has since changed on
 * the Settings page keeps its value: firstOrCreate, never updateOrCreate. The
 * point is "a fresh database has sensible values", not "reset to defaults".
 */
class HouseholdDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $this->seedCookSchedule();
    }

    private function seedSettings(): void
    {
        foreach (HouseholdSettings::defaults() as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    /**
     * "Every night: together, 30 min (until the household sets its week)".
     */
    private function seedCookSchedule(): void
    {
        foreach (Weekday::week() as $weekday) {
            CookScheduleNight::query()->firstOrCreate(
                ['weekday' => $weekday->value],
                [
                    'cook' => CookScheduleNight::DEFAULT_COOK,
                    'time_budget' => CookScheduleNight::DEFAULT_TIME_BUDGET,
                ],
            );
        }
    }
}
