<?php

namespace App\Enums;

/**
 * What was chosen for a night on a plan.
 *
 * A PlanNight's selection column is nullable: null means "not chosen yet", which
 * is the state between creating the plan's nights and the picker (MM-6) filling
 * them in. Once set, exactly one of the matching columns carries the choice:
 *
 *   Recipe      -> recipe_id
 *   FreezerItem -> freezer_item_id
 *   NightOff    -> neither
 *   NothingFits -> neither, and pick_note says why
 *
 * NothingFits is a decision, not an absence: the picker looked, and the rules
 * ruled everything out. It is kept apart from null so a page can tell "not
 * planned yet" from "nothing in the vault fits this night", and apart from
 * NightOff because a night off is the household's own plan, not a dead end.
 */
enum NightSelection: string
{
    case Recipe = 'recipe';
    case FreezerItem = 'freezer_item';
    case NightOff = 'night_off';
    case NothingFits = 'nothing_fits';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => 'Recipe',
            self::FreezerItem => 'Freezer meal',
            self::NightOff => 'Night off',
            self::NothingFits => 'Nothing fits',
        };
    }

    /**
     * Is there something to cook or heat up on this night?
     */
    public function hasMeal(): bool
    {
        return $this === self::Recipe || $this === self::FreezerItem;
    }
}
