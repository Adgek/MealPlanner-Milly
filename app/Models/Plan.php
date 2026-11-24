<?php

namespace App\Models;

use App\Enums\Weekday;
use App\Planning\CalendarDate;
use App\Planning\ShoppingWindow;
use App\Settings\HouseholdSettings;
use Carbon\CarbonImmutable;
use Database\Factories\PlanFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The plan for one shopping window.
 *
 * @property CarbonImmutable $shopping_date
 * @property CarbonImmutable $first_night
 * @property CarbonImmutable $last_night
 * @property CarbonImmutable|null $grocery_list_changed_at
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
        'grocery_list_changed_at',
    ];

    protected function casts(): array
    {
        return [
            // Calendar dates in the household's time zone. Stored as DATE, so
            // unlike a UTC timestamp they cannot slide to the previous day.
            'shopping_date' => 'immutable_date',
            'first_night' => 'immutable_date',
            'last_night' => 'immutable_date',
            // An instant rather than a date: it answers "when did the grocery
            // list change", which is compared against the moment of the shop.
            'grocery_list_changed_at' => 'immutable_datetime',
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

    /**
     * The window this plan was built for, as the planner describes one.
     *
     * Read off the stored dates rather than derived from the shopping days as
     * they stand now: a plan already out keeps the nights it was built for, which
     * is why MM-6 stores them at all (docs/decisions.md).
     */
    public function window(): ShoppingWindow
    {
        return new ShoppingWindow($this->shopping_date, $this->first_night, $this->last_night);
    }

    /**
     * Has every night of this window gone by?
     *
     * Then the plan is history and nothing about it can change any more, because
     * the planner refuses to touch a night that has passed
     * (PlanNight::hasPassed()). MM-7 reads this to leave a finished window's
     * grocery list exactly as it was shopped.
     */
    public function hasPassed(): bool
    {
        return CalendarDate::hasPassed($this->last_night, HouseholdSettings::timeZone());
    }

    /**
     * Has the grocery list ever been made for this plan?
     */
    public function hasGroceryList(): bool
    {
        return $this->grocery_list_changed_at !== null;
    }

    /**
     * The moment the shop happens: this plan's shopping date at the household's
     * time for that weekday, on the kitchen clock.
     *
     * Null when the household no longer shops on that weekday — the shopping days
     * are a setting they can change (MM-4), and a plan keeps the date it was made
     * for. A caller that cannot place the shop in time should say nothing rather
     * than invent a time.
     */
    public function shoppingMoment(): ?CarbonImmutable
    {
        $weekday = Weekday::fromDate($this->shopping_date);

        foreach (HouseholdSettings::shoppingTimes() as $shop) {
            if ($shop['weekday'] === $weekday) {
                return HouseholdSettings::at($shop['time'], $this->shopping_date)->toImmutable();
            }
        }

        return null;
    }

    /**
     * Did the grocery list change after the household had already shopped?
     *
     * The warning MM-8 owes the fridge: the list somebody carried round the shop
     * is no longer the list the plan asks for. False when the list has never been
     * made, and false when the shop cannot be placed in time, because "we can't
     * tell" is not "it changed".
     */
    public function groceryListChangedAfterShopping(): bool
    {
        $shop = $this->shoppingMoment();

        return $shop !== null
            && $this->grocery_list_changed_at !== null
            && $this->grocery_list_changed_at->greaterThan($shop);
    }

    /**
     * Record that the grocery list changed, now or at a given moment.
     *
     * Only a rebuild that really moved a line calls this, so the stamp means "the
     * list changed then" rather than "somebody looked at it then".
     */
    public function recordGroceryListChange(?DateTimeInterface $at = null): void
    {
        $this->grocery_list_changed_at = $at === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($at);

        $this->save();
    }
}
