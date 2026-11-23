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
     * Hand every portion this plan's nights are holding back to the freezer
     * before the plan goes.
     *
     * This has to live on the parent. `plan_nights.plan_id` cascades in MySQL, so
     * deleting a plan removes its nights without Eloquent ever loading them and
     * **no `deleting` event on `PlanNight` can fire** — a model event on the child
     * would be silently dead code. The cascade is worth keeping (it is what makes
     * a half-deleted plan impossible), so the plan releases the reservations
     * itself, on its way out.
     *
     * Without this, deleting a plan that held a freezer meal would lose the
     * portion for good: the stash would stay down by one for a dinner nobody ate,
     * and MM-12's low-stash warning would be counting a number that never
     * happened.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $plan) {
            $plan->nights()->get()->each(fn (PlanNight $night) => $night->releaseFreezerPortion());
        });
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
