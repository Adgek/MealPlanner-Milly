<?php

namespace App\Models;

use App\Enums\CookableBy;
use App\Enums\EffortTier;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A recipe in the vault (AppPlan.md, section 1).
 *
 * @property string $name
 * @property CookableBy $cookable_by
 * @property EffortTier $effort_tier
 * @property string|null $kid_off_ramp
 *
 * @method static RecipeFactory factory($count = null, $state = [])
 */
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'cookable_by',
        'effort_tier',
        'kid_off_ramp',
    ];

    protected function casts(): array
    {
        return [
            'cookable_by' => CookableBy::class,
            'effort_tier' => EffortTier::class,
        ];
    }

    protected static function booted(): void
    {
        /*
         * Prep steps and the bulk-ingredient pivot have real foreign keys, so
         * MySQL clears those itself. Ingredients are polymorphic, which cannot
         * carry a foreign key, so nothing in the database cleans them up: without
         * this, deleting a recipe leaves its ingredients behind forever.
         *
         * This is a model event, so it covers $recipe->delete() but not a mass
         * delete like Recipe::query()->delete(), which bypasses events.
         */
        static::deleting(fn (self $recipe) => $recipe->ingredients()->delete());
    }

    /**
     * What to buy for it.
     *
     * @return MorphMany<Ingredient, $this>
     */
    public function ingredients(): MorphMany
    {
        return $this->morphMany(Ingredient::class, 'ingredientable');
    }

    /**
     * What has to happen before the night of, in order.
     *
     * @return HasMany<RecipePrepStep, $this>
     */
    public function prepSteps(): HasMany
    {
        return $this->hasMany(RecipePrepStep::class)->orderBy('position');
    }

    /**
     * The bulk packs this recipe can split with another (MM-11).
     *
     * @return BelongsToMany<BulkIngredient, $this>
     */
    public function bulkIngredients(): BelongsToMany
    {
        return $this->belongsToMany(BulkIngredient::class);
    }

    /**
     * Every time we have cooked it.
     *
     * @return HasMany<MealHistoryEntry, $this>
     */
    public function mealHistory(): HasMany
    {
        return $this->hasMany(MealHistoryEntry::class);
    }

    /**
     * @return HasMany<PlanNight, $this>
     */
    public function planNights(): HasMany
    {
        return $this->hasMany(PlanNight::class);
    }
}
