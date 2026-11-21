<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MealHistoryEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record that we cooked a particular recipe on a particular day.
 *
 * What variety is measured against: the picker favours recipes that haven't come
 * up recently (docs/decisions.md).
 *
 * @property CarbonImmutable $cooked_on
 *
 * @method static MealHistoryEntryFactory factory($count = null, $state = [])
 */
class MealHistoryEntry extends Model
{
    /** @use HasFactory<MealHistoryEntryFactory> */
    use HasFactory;

    /**
     * "meal_history" reads better than "meal_history_entries" for the thing a
     * person opens in MySQL; the model keeps the singular-row name.
     */
    protected $table = 'meal_history';

    protected $fillable = [
        'cooked_on',
        'recipe_id',
    ];

    protected function casts(): array
    {
        return [
            'cooked_on' => 'immutable_date',
        ];
    }

    /**
     * Entries from the last $days days.
     *
     * @param  Builder<MealHistoryEntry>  $query
     */
    public function scopeSince(Builder $query, Carbon $date): void
    {
        $query->where('cooked_on', '>=', $date->toDateString());
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
