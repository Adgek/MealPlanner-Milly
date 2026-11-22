<?php

namespace App\Models;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use Carbon\CarbonImmutable;
use Database\Factories\PlanNightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night of a plan: who cooks, how long they have, and what they are making.
 *
 * @property CarbonImmutable $date
 * @property CookAssignment $cook
 * @property EffortTier $time_budget
 * @property bool $overrides_schedule
 * @property string|null $override_note
 * @property string|null $pick_note
 * @property NightSelection|null $selection
 * @property bool $is_locked
 *
 * @method static PlanNightFactory factory($count = null, $state = [])
 */
class PlanNight extends Model
{
    /** @use HasFactory<PlanNightFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'date',
        'cook',
        'time_budget',
        'overrides_schedule',
        'override_note',
        'pick_note',
        'selection',
        'recipe_id',
        'freezer_item_id',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'cook' => CookAssignment::class,
            'time_budget' => EffortTier::class,
            'selection' => NightSelection::class,
            'overrides_schedule' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * Has anything been chosen for this night yet?
     *
     * True once the picker has had a go, including the nights it had to give up
     * on: "nothing fits" is a decision, and re-planning should not quietly roll
     * the same dead end again.
     */
    public function isPlanned(): bool
    {
        return $this->selection !== null;
    }

    /**
     * Is there something to cook or heat up on this night?
     *
     * False for a night off and for a night nothing fitted — both of which are
     * planned, and neither of which is dinner.
     */
    public function hasMeal(): bool
    {
        return $this->selection?->hasMeal() ?? false;
    }

    /**
     * Did the picker run out of options here?
     */
    public function nothingFits(): bool
    {
        return $this->selection === NightSelection::NothingFits;
    }

    /**
     * May the picker overwrite this night on a re-roll?
     *
     * A locked night is the household's answer, and survives a re-roll untouched
     * (docs/decisions.md, "Plan review").
     */
    public function isRerollable(): bool
    {
        return ! $this->is_locked;
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The recipe chosen, when the selection is a recipe.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * The freezer meal chosen, when the selection is a freezer item.
     *
     * @return BelongsTo<FreezerItem, $this>
     */
    public function freezerItem(): BelongsTo
    {
        return $this->belongsTo(FreezerItem::class);
    }
}
