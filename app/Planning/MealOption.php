<?php

namespace App\Planning;

use Carbon\CarbonImmutable;

/**
 * Something that can be dinner: a recipe from the vault, or a meal already in
 * the freezer.
 *
 * The picker works with these rather than with Eloquent models, so every rule
 * can be unit-tested with plain values and no database (AGENTS.md).
 */
interface MealOption
{
    /**
     * A stable identity for "the same meal", so a window can refuse to use one
     * twice. Unique across kinds: a recipe and a freezer item never collide.
     */
    public function key(): string;

    /**
     * What it is called on the plan.
     */
    public function name(): string;

    /**
     * When it was last cooked, or null if it never has been.
     *
     * What variety is measured against: the picker favours meals that haven't
     * come up recently.
     */
    public function lastCookedOn(): ?CarbonImmutable;
}
