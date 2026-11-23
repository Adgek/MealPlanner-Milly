<?php

namespace App\Models;

use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Enums\NightSelection;
use App\Planning\CalendarDate;
use App\Settings\HouseholdSettings;
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
 * @property array<string, mixed>|null $pick_reason
 * @property NightSelection|null $selection
 * @property bool $holds_freezer_portion
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
        'pick_reason',
        'selection',
        'recipe_id',
        'freezer_item_id',
        'holds_freezer_portion',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'cook' => CookAssignment::class,
            'time_budget' => EffortTier::class,
            'selection' => NightSelection::class,
            // The counts behind "nothing fits", read back through
            // App\Planning\NothingFitsReason. Stored rather than rendered so a
            // cook's new name reaches rows written months ago (MM-4).
            'pick_reason' => 'array',
            'overrides_schedule' => 'boolean',
            'holds_freezer_portion' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * Has anything been chosen for this night yet?
     *
     * True once the picker has had a go, including the nights it had to give up
     * on: "nothing fits" is a decision the picker reached, not an absence of one,
     * which is what tells a page "the rules ruled everything out here" apart from
     * "nobody has planned this yet".
     *
     * It is not the question "may the picker have another go" — see needsPicking().
     */
    public function isPlanned(): bool
    {
        return $this->selection !== null;
    }

    /**
     * Should a plain plan run have a go at this night?
     *
     * A night nobody has picked for, and a night nothing fitted. The second one
     * matters because the reason the picker stored is advice — "add a quick recipe
     * she likes", "restock the freezer" — and taking that advice has to be enough:
     * a plain `milly:plan` is what the household will run next. Before this, the
     * dead end was treated as settled, so adding the three quick recipes the note
     * asked for changed nothing and only `--replan` filled the night, which
     * re-rolled every other night with it.
     *
     * The dead end is not a decision worth keeping, because the thing that made it
     * a dead end is exactly what the household has just changed. A night that did
     * fit is left alone, and a locked one is left alone whatever it holds.
     */
    public function needsPicking(): bool
    {
        return ! $this->isPlanned() || $this->nothingFits();
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
     * Has this night already gone by?
     *
     * Tonight has not: a night is only history once its date is behind today on
     * the **kitchen** clock, so the household can still change their mind at 8pm
     * in Toronto when the server already says tomorrow.
     *
     * The one definition of the fence, because more than one thing needs it: the
     * planner refuses to re-roll or swap a past night (docs/decisions.md), and
     * releasing a past night's freezer portion would put food back in the stash
     * that was eaten.
     */
    public function hasPassed(): bool
    {
        return CalendarDate::of($this->date)->lessThan(CalendarDate::today(HouseholdSettings::timeZone()));
    }

    /**
     * Is this night holding a portion out of the freezer stash?
     *
     * The reservation says so itself rather than being read off "the selection is
     * a freezer item": a row written by a factory, a seeder or an import points at
     * a freezer item without ever having spent a portion, and the ledger has to
     * tell the two apart (docs/decisions.md, "Freezer stash").
     */
    public function holdsFreezerPortion(): bool
    {
        return $this->holds_freezer_portion === true;
    }

    /**
     * Put this night's freezer portion back in the stash, and say whether one
     * really went back.
     *
     * The only thing that ever increments portions_left, and it is conditional on
     * the reservation: releasing twice, releasing a hand-written row, or releasing
     * a night that never reserved cannot invent food. Nor can releasing a night
     * that has gone by — that portion was eaten, so the flag is dropped without
     * the stash moving. The flag is cleared here but the row is left to the caller
     * to save — the planner saves the whole night once, and Plan's cascade is
     * about to delete it.
     */
    public function releaseFreezerPortion(): bool
    {
        if (! $this->holdsFreezerPortion()) {
            return false;
        }

        // The night gives the reservation up either way. Whether the stash gets
        // the portion back is the separate question.
        $this->holds_freezer_portion = false;

        if ($this->hasPassed() || $this->freezer_item_id === null) {
            return false;
        }

        FreezerItem::query()->whereKey($this->freezer_item_id)->increment('portions_left');

        return true;
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
