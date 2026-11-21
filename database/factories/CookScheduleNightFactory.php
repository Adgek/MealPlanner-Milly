<?php

namespace Database\Factories;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use App\Models\CookScheduleNight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CookScheduleNight>
 */
class CookScheduleNightFactory extends Factory
{
    protected $model = CookScheduleNight::class;

    public function definition(): array
    {
        return [
            'weekday' => fake()->unique()->randomElement(Weekday::cases()),
            'cook' => CookAssignment::Together,
            'time_budget' => EffortTier::Workhorse30,
        ];
    }

    public function on(Weekday $weekday): static
    {
        return $this->state(['weekday' => $weekday]);
    }

    public function cookedBy(CookAssignment $cook): static
    {
        return $this->state(['cook' => $cook]);
    }

    public function withTime(EffortTier $tier): static
    {
        return $this->state(['time_budget' => $tier]);
    }
}
