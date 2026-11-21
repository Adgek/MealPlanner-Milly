<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KitchenProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A recurring kitchen project: stock, sauces, hummus, a big batch of soup
 * (AppPlan.md, section 2).
 *
 * @property string $name
 * @property int $every_days
 * @property int|null $produces_portions
 * @property CarbonImmutable|null $last_done_on
 *
 * @method static KitchenProjectFactory factory($count = null, $state = [])
 */
class KitchenProject extends Model
{
    /** @use HasFactory<KitchenProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'every_days',
        'produces_freezer_item_id',
        'produces_portions',
        'last_done_on',
    ];

    protected function casts(): array
    {
        return [
            'every_days' => 'integer',
            'produces_portions' => 'integer',
            // A calendar date, not an instant: no time, no offset, no drift.
            'last_done_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        // Ingredients are polymorphic, so there is no foreign key for MySQL to
        // cascade. See the same note on Recipe.
        static::deleting(fn (self $project) => $project->ingredients()->delete());
    }

    /**
     * The date this project is next due.
     *
     * A project that has never been done is due today, which is what makes a
     * freshly imported list of projects show up in the first plan.
     */
    public function dueOn(?Carbon $today = null): Carbon
    {
        $today = $today ?? Carbon::today();

        if ($this->last_done_on === null) {
            return $today;
        }

        return Carbon::parse($this->last_done_on)->addDays($this->every_days);
    }

    /**
     * What it puts in the freezer, if anything.
     *
     * @return BelongsTo<FreezerItem, $this>
     */
    public function producesFreezerItem(): BelongsTo
    {
        return $this->belongsTo(FreezerItem::class, 'produces_freezer_item_id');
    }

    /**
     * What to buy for it. Shares the ingredients table with recipes, so the
     * grocery list picks both up in one pass.
     *
     * @return MorphMany<Ingredient, $this>
     */
    public function ingredients(): MorphMany
    {
        return $this->morphMany(Ingredient::class, 'ingredientable');
    }
}
