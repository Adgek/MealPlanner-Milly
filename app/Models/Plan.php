<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The plan for one shopping window.
 *
 * @property CarbonImmutable $shopping_date
 * @property CarbonImmutable $first_night
 * @property CarbonImmutable $last_night
 *
 * @method static PlanFactory factory($count = null, $state = [])
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'shopping_date',
        'first_night',
        'last_night',
    ];

    protected function casts(): array
    {
        return [
            // Calendar dates in the household's time zone. Stored as DATE, so
            // unlike a UTC timestamp they cannot slide to the previous day.
            'shopping_date' => 'immutable_date',
            'first_night' => 'immutable_date',
            'last_night' => 'immutable_date',
        ];
    }

    /**
     * The nights this shop has to cover, in order.
     *
     * @return HasMany<PlanNight, $this>
     */
    public function nights(): HasMany
    {
        return $this->hasMany(PlanNight::class)->orderBy('date');
    }

    /**
     * The grocery checklist, including which lines have been ticked off.
     *
     * @return HasMany<PlanGroceryItem, $this>
     */
    public function groceryItems(): HasMany
    {
        return $this->hasMany(PlanGroceryItem::class);
    }
}
