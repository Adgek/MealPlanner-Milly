<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlanGroceryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a plan's grocery checklist, and whether it has been ticked.
 *
 * @property string $name
 * @property array<int, string>|null $meals
 * @property float|null $quantity
 * @property string|null $unit
 * @property string|null $store_section
 * @property bool $pantry_basic
 * @property bool $checked
 * @property CarbonImmutable|null $checked_at
 *
 * @method static PlanGroceryItemFactory factory($count = null, $state = [])
 */
class PlanGroceryItem extends Model
{
    /** @use HasFactory<PlanGroceryItemFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'name',
        'meals',
        'quantity',
        'unit',
        'store_section',
        'pantry_basic',
        'checked',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            // The meals of the window that want this line, in night order. Stored
            // with the line rather than read off the plan, so the list can say
            // why an item is on it without the plan behind it (MM-7).
            'meals' => 'array',
            'quantity' => 'float',
            'pantry_basic' => 'boolean',
            'checked' => 'boolean',
            // An instant, so UTC in the column; render it in the household zone.
            'checked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Tick the line off, recording when, and say whether this call is the one that
     * did it.
     *
     * The first tick wins, and the database is what decides — not the row in
     * memory. Both partners open the list while a line is unticked, so each holds
     * a model that says `checked = false`; a guard read off the instance would let
     * them both write, and the second would move the timestamp. The condition is
     * part of the update instead, so only one of the two can match a row (MM-8).
     *
     * The loser is refreshed, so the phone that lost shows the tick that won.
     */
    public function check(): bool
    {
        $at = now();

        $ticked = $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->where('checked', false)
            ->update(['checked' => true, 'checked_at' => $at]) === 1;

        if (! $ticked) {
            $this->refresh();

            return false;
        }

        $this->forceFill(['checked' => true, 'checked_at' => $at])->syncOriginal();

        return true;
    }

    /**
     * Put it back on the list.
     */
    public function uncheck(): bool
    {
        return $this->forceFill([
            'checked' => false,
            'checked_at' => null,
        ])->save();
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
