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
 */
enum NightSelection: string
{
    case Recipe = 'recipe';
    case FreezerItem = 'freezer_item';
    case NightOff = 'night_off';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => 'Recipe',
            self::FreezerItem => 'Freezer meal',
            self::NightOff => 'Night off',
        };
    }
}
