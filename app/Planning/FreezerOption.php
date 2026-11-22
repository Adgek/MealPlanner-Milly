<?php

namespace App\Planning;

use App\Models\FreezerItem;
use Carbon\CarbonImmutable;

/**
 * One line of the freezer stash, as the picker sees it.
 *
 * A freezer meal is the 80%-prepped off-ramp: it needs no cook tag (whoever is
 * on has to be able to heat it) and no effort tier of its own, because thawing
 * and reheating is a chaos-night job by definition (AppPlan.md, "Time / Effort
 * Tier"). What it does have is a portion count, and planning a night spends one.
 *
 * It carries no history: meal_history records recipes, and a spent portion is
 * already the record that a freezer meal was used.
 */
final class FreezerOption implements MealOption
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $portionsLeft,
    ) {}

    public static function fromModel(FreezerItem $item): self
    {
        return new self(
            id: $item->id,
            name: $item->name,
            portionsLeft: $item->portions_left,
        );
    }

    public function key(): string
    {
        return 'freezer:'.$this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function lastCookedOn(): ?CarbonImmutable
    {
        return null;
    }
}
