<?php

namespace App\Grocery;

use App\Models\Ingredient;
use App\Models\KidBackupFood;

/**
 * One thing one meal needs from the store, as the list is built.
 *
 * The grain before merging: a recipe's "1 lb ground beef" and another recipe's
 * "1 lb ground beef" are two needs that become one line. It carries plain values
 * only, so the merge can be tested with no database behind it (AGENTS.md).
 */
final class IngredientNeed
{
    /**
     * Where a kid backup food lands when nothing else on the list wants it, and
     * what it says it is for.
     *
     * The kid off-ramp inventory has no store section of its own
     * (`kid_backup_foods`), so these items group together rather than falling in
     * with the unsectioned odds and ends. One a recipe also asks for merges into
     * that recipe's line and keeps its real section.
     */
    public const KID_BACKUP = 'Kid backup';

    public readonly string $name;

    public readonly ?string $unit;

    public readonly ?string $storeSection;

    public function __construct(
        string $name,
        public readonly ?float $quantity = null,
        ?string $unit = null,
        ?string $storeSection = null,
        public readonly bool $pantryBasic = false,
        /**
         * The meal that wants it, so the list can show which dinners each line is
         * for. Null for something the window needs whoever is cooking.
         */
        public readonly ?string $meal = null,
    ) {
        $this->name = MergeKey::tidy($name);
        $this->unit = MergeKey::tidy($unit) ?: null;
        $this->storeSection = MergeKey::tidy($storeSection) ?: null;
    }

    /**
     * A recipe's (or a kitchen project's) ingredient row, for a named meal.
     */
    public static function fromIngredient(Ingredient $ingredient, ?string $meal = null): self
    {
        return new self(
            name: $ingredient->name,
            quantity: $ingredient->quantity,
            unit: $ingredient->unit,
            storeSection: $ingredient->store_section,
            pantryBasic: $ingredient->pantry_basic,
            meal: $meal,
        );
    }

    /**
     * A kid backup food: always on the list, never a pantry basic — the whole
     * point is that the household has it in when a dinner goes wrong
     * (AppPlan.md, section 3).
     */
    public static function fromKidBackupFood(KidBackupFood $food): self
    {
        return new self(
            name: $food->name,
            quantity: $food->quantity,
            unit: $food->unit,
            storeSection: self::KID_BACKUP,
            pantryBasic: false,
            meal: self::KID_BACKUP,
        );
    }

    /**
     * The same need, said to be for a particular meal. Null leaves it as it is,
     * so a caller with nothing to say about the meal cannot wipe the one there.
     */
    public function withMeal(?string $meal): self
    {
        if ($meal === null || $meal === $this->meal) {
            return $this;
        }

        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            storeSection: $this->storeSection,
            pantryBasic: $this->pantryBasic,
            meal: $meal,
        );
    }

    public function mergeKey(): string
    {
        return MergeKey::of($this->name, $this->unit);
    }

    /**
     * The section of the store this lands in, with a home for the things nobody
     * has given a section.
     */
    public function section(): string
    {
        return $this->storeSection ?? GrocerySection::UNSECTIONED;
    }

    /**
     * Did whoever wrote it say how much?
     *
     * Both the amount and the unit are optional on an ingredient, for the things
     * nobody measures ("a splash of soy").
     */
    public function isMeasured(): bool
    {
        return $this->quantity !== null;
    }
}
