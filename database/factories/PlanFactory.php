<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * How many windows this process has built.
     *
     * shopping_date is unique — one plan per shopping window — so a factory that
     * returned the same date twice could only ever create one plan. Each call
     * takes the next Sunday along instead, which is also what consecutive windows
     * look like in real life.
     */
    protected static int $windowsBuilt = 0;

    public function definition(): array
    {
        // A Sunday shop covering Monday to Wednesday, the default window.
        $shoppingDate = Carbon::parse('next sunday')
            ->startOfDay()
            ->addWeeks(static::$windowsBuilt++);

        return [
            'shopping_date' => $shoppingDate->toDateString(),
            'first_night' => $shoppingDate->copy()->addDay()->toDateString(),
            'last_night' => $shoppingDate->copy()->addDays(3)->toDateString(),
        ];
    }

    /**
     * A window starting from a given shopping date and covering $nights nights,
     * beginning the day after the shop.
     */
    public function window(string|Carbon $shoppingDate, int $nights = 3): static
    {
        $date = $shoppingDate instanceof Carbon ? $shoppingDate->copy() : Carbon::parse($shoppingDate);

        return $this->state([
            'shopping_date' => $date->toDateString(),
            'first_night' => $date->copy()->addDay()->toDateString(),
            'last_night' => $date->copy()->addDays($nights)->toDateString(),
        ]);
    }
}
