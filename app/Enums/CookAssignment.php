<?php

namespace App\Enums;

/**
 * Who is cooking on a given night.
 *
 * Used both by the weekly cook schedule in Settings (the household's normal week)
 * and by a single night on a plan, where it can be overridden one-off for travel,
 * leftovers or eating out (docs/decisions.md, "Proposed defaults").
 *
 * Distinct from CookableBy on purpose: a recipe is tagged me/partner/both, while
 * a night can also be cooked "together" or by "nobody".
 */
enum CookAssignment: string
{
    case Me = 'me';
    case Partner = 'partner';
    case Together = 'together';
    case Nobody = 'nobody';

    public function label(): string
    {
        return match ($this) {
            self::Me => 'Me',
            self::Partner => 'Partner',
            self::Together => 'Together',
            self::Nobody => 'Nobody',
        };
    }

    /**
     * Does anybody cook on this night? "Nobody" nights still exist on the plan —
     * they are how travel, leftovers and eating out are recorded.
     */
    public function cooks(): bool
    {
        return $this !== self::Nobody;
    }
}
