<?php

namespace App\Planning;

use App\Enums\CookableBy;
use App\Enums\CookAssignment;
use App\Enums\EffortTier;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One recipe from the vault, as the picker sees it.
 */
final class RecipeOption implements MealOption
{
    public readonly ?CarbonImmutable $lastCookedOn;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly CookableBy $cookableBy,
        public readonly EffortTier $effortTier,
        ?DateTimeInterface $lastCookedOn = null,
    ) {
        $this->lastCookedOn = $lastCookedOn === null ? null : CalendarDate::of($lastCookedOn);
    }

    public static function fromModel(Recipe $recipe, ?DateTimeInterface $lastCookedOn = null): self
    {
        return new self(
            id: $recipe->id,
            name: $recipe->name,
            cookableBy: $recipe->cookable_by,
            effortTier: $recipe->effort_tier,
            lastCookedOn: $lastCookedOn,
        );
    }

    public function key(): string
    {
        return 'recipe:'.$this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function lastCookedOn(): ?CarbonImmutable
    {
        return $this->lastCookedOn;
    }

    /**
     * Would whoever is cooking this night make it?
     *
     * My nights take recipes tagged me or both, my partner's take partner or
     * both, and on a night we cook together any recipe can be used (AppPlan.md,
     * "Who is cooking that night"). Nobody nights never get here: they get no
     * recipe at all.
     */
    public function cookableOn(CookAssignment $cook): bool
    {
        return match ($cook) {
            CookAssignment::Me => $this->cookableBy !== CookableBy::Partner,
            CookAssignment::Partner => $this->cookableBy !== CookableBy::Me,
            CookAssignment::Together => true,
            CookAssignment::Nobody => false,
        };
    }

    /**
     * Does it fit the time there is?
     *
     * "Fits" is at most, not exactly: a 15-minute meal is fine on a night with an
     * hour. The other direction is what the rule is for — a 60-minute project
     * cannot be squeezed into a chaos night.
     */
    public function fitsIn(EffortTier $timeAvailable): bool
    {
        return $this->effortTier->minutes() <= $timeAvailable->minutes();
    }
}
