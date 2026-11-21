<?php

namespace App\Models;

use App\Settings\HouseholdSettings;
use Database\Factories\BulkIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An ingredient worth buying in bulk and splitting across two meals.
 *
 * @property string $name
 * @property int|null $fridge_life_days
 *
 * @method static BulkIngredientFactory factory($count = null, $state = [])
 */
class BulkIngredient extends Model
{
    /** @use HasFactory<BulkIngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'fridge_life_days',
    ];

    protected function casts(): array
    {
        return [
            'fridge_life_days' => 'integer',
        ];
    }

    /**
     * How long the raw second half keeps, in days.
     *
     * Falls back to the household's raw_split_fridge_life_days setting when this
     * ingredient doesn't record its own, which is the rule in the ticket: "if it
     * is not set, the Settings default applies". Read this rather than the column
     * when deciding whether a pairing fits in the window (MM-11).
     */
    public function fridgeLifeDays(): int
    {
        return $this->fridge_life_days ?? HouseholdSettings::rawSplitFridgeLifeDays();
    }

    /**
     * @return BelongsToMany<Recipe, $this>
     */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class);
    }
}
