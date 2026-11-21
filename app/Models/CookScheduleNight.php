<?php

namespace App\Models;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\Weekday;
use Database\Factories\CookScheduleNightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One night of the household's normal week: who cooks, and how long they have.
 *
 * Seven of these are the weekly cook schedule from Settings. A plan night copies
 * its values from here rather than joining to it, so editing the week never
 * rewrites a plan that is already out.
 *
 * @property Weekday $weekday
 * @property CookAssignment $cook
 * @property EffortTier $time_budget
 *
 * @method static CookScheduleNightFactory factory($count = null, $state = [])
 */
class CookScheduleNight extends Model
{
    /** @use HasFactory<CookScheduleNightFactory> */
    use HasFactory;

    protected $fillable = [
        'weekday',
        'cook',
        'time_budget',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
            'cook' => CookAssignment::class,
            'time_budget' => EffortTier::class,
        ];
    }

    /**
     * The schedule for one weekday.
     */
    public static function forWeekday(Weekday $weekday): ?self
    {
        return static::query()->where('weekday', $weekday->value)->first();
    }

    /**
     * The whole week, keyed by weekday value and ordered Monday first.
     *
     * @return Collection<string, self>
     */
    public static function week(): Collection
    {
        return static::query()
            ->get()
            ->sortBy(fn (self $night) => $night->weekday->isoNumber())
            ->keyBy(fn (self $night) => $night->weekday->value);
    }
}
