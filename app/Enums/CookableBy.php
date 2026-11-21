<?php

namespace App\Enums;

/**
 * Who is willing to cook a recipe (AppPlan.md, "The Recipe Vault").
 *
 * This is a property of the recipe, not of a night: it answers "may this recipe
 * ever be assigned to this person?". A night's actual assignment is a
 * CookAssignment, which also covers "together" and "nobody".
 *
 * The point of the tag is that the picker (MM-6) never hands my partner a recipe
 * from the vault she doesn't want to make on her solo nights.
 */
enum CookableBy: string
{
    case Me = 'me';
    case Partner = 'partner';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Me => 'Me',
            self::Partner => 'Partner',
            self::Both => 'Both',
        };
    }
}
