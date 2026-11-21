<?php

namespace App\Models;

use Database\Factories\RecipePrepStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of upstream prep for a recipe — "soak the beans", 24 hours ahead.
 *
 * @property string $description
 * @property int $lead_time_hours
 * @property int $position
 *
 * @method static RecipePrepStepFactory factory($count = null, $state = [])
 */
class RecipePrepStep extends Model
{
    /** @use HasFactory<RecipePrepStepFactory> */
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'description',
        'lead_time_hours',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'lead_time_hours' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * Does this step happen on an earlier day than the meal itself?
     *
     * Anything a day or more ahead is what the upstream prep nudge in MM-13 is
     * for; a 20-minute marinade is just part of cooking.
     */
    public function isAheadOfTheDay(): bool
    {
        return $this->lead_time_hours >= 24;
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
