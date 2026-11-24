<?php

namespace App\Models\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Something the household can take out of circulation without erasing it
 * (MM-15): a recipe they are not going to make again, a freezer meal they have
 * stopped batching.
 *
 * Retiring is what "remove" means for anything a plan night or meal history can
 * point at. The row stays, so:
 *
 *   - a night that has gone by still says what was eaten, and `plan_nights` is
 *     the only record that it was (MM-6);
 *   - meal history keeps pointing at a real recipe, which is the only input the
 *     variety weighting has;
 *   - nothing is left pointing at a missing row, so no page renders a blank
 *     dinner.
 *
 * What changes is that the picker stops offering it: MealPool::fromVault() draws
 * from `inTheVault()` only. A retired thing can be brought back, and the count on
 * a retired freezer item is left exactly as it was — the portions are still in
 * the freezer, they are just not planned around any more.
 *
 * Not Laravel's SoftDeletes, which hides the row from every relation and would
 * cause precisely the blank dinners this avoids. See the migrations.
 *
 * @property CarbonImmutable|null $retired_at
 */
trait Retirable
{
    /**
     * Everything the picker may still choose.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInTheVault(Builder $query): void
    {
        $query->whereNull('retired_at');
    }

    /**
     * Everything the household has taken out of circulation.
     *
     * @param  Builder<static>  $query
     */
    public function scopeRetired(Builder $query): void
    {
        $query->whereNotNull('retired_at');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Take it out of circulation, and say whether that changed anything.
     *
     * Idempotent: retiring something twice keeps the date it was first retired
     * on, so the household can tell how long ago they stopped making it.
     */
    public function retire(): bool
    {
        if ($this->isRetired()) {
            return false;
        }

        return $this->forceFill(['retired_at' => now()])->save();
    }

    /**
     * Put it back in the vault.
     */
    public function bringBack(): bool
    {
        if (! $this->isRetired()) {
            return false;
        }

        return $this->forceFill(['retired_at' => null])->save();
    }
}
