<?php

namespace App\Grocery;

use App\Models\PlanGroceryItem;
use Carbon\CarbonImmutable;

/**
 * One line of the grocery list: a thing to buy, how much of it, and which meals
 * of the window want it.
 *
 * Needs with the same name and unit merge into one of these ("1 lb + 1 lb ground
 * beef" becomes "2 lb"); needs whose units differ stay two lines, because nothing
 * here converts between units (MergeKey).
 *
 * The same class carries a line that has been stored — then it also knows its row
 * id and whether the household has ticked it off, which is what MM-8 renders and
 * ticks.
 */
final class GroceryLine
{
    /**
     * @param  array<int, string>  $meals  the dinners that want it, in night order
     */
    public function __construct(
        public readonly string $name,
        public readonly ?float $quantity = null,
        public readonly ?string $unit = null,
        public readonly string $section = GrocerySection::UNSECTIONED,
        public readonly bool $pantryBasic = false,
        public readonly array $meals = [],
        /** The `plan_grocery_items` row, once the line is stored. */
        public readonly ?int $id = null,
        public readonly bool $checked = false,
        public readonly ?CarbonImmutable $checkedAt = null,
    ) {}

    /**
     * The first need for something: one line, so far.
     */
    public static function from(IngredientNeed $need): self
    {
        return new self(
            name: $need->name,
            quantity: $need->quantity,
            unit: $need->unit,
            section: $need->section(),
            pantryBasic: $need->pantryBasic,
            meals: $need->meal === null ? [] : [$need->meal],
        );
    }

    /**
     * A line as it stands on the stored checklist, ticks and all.
     */
    public static function fromStoredItem(PlanGroceryItem $item): self
    {
        return new self(
            name: $item->name,
            quantity: $item->quantity,
            unit: $item->unit,
            section: MergeKey::tidy($item->store_section) ?: GrocerySection::UNSECTIONED,
            pantryBasic: $item->pantry_basic,
            meals: array_values(array_map('strval', $item->meals ?? [])),
            id: $item->getKey(),
            checked: $item->checked,
            checkedAt: $item->checked_at,
        );
    }

    /**
     * This line with one more need for the same thing folded in.
     *
     * The rules, all of which are in docs/decisions.md because MM-11 and MM-12
     * add to the same list:
     *
     *   - **amounts add up**, and a need that says no amount adds nothing to the
     *     number while still putting its meal on the line. A line nobody measured
     *     keeps no amount at all;
     *   - **the first spelling wins**, so the earliest night's capitalisation is
     *     what the household reads;
     *   - **the first real store section wins.** Two recipes that disagree about
     *     where a thing lives still make one line, because merging the amount
     *     matters more than the aisle;
     *   - **a line is a pantry basic only if every need says so.** If one meal
     *     treats it as something to buy, it belongs on the list proper rather
     *     than in the short "check you have" section, where it could be missed.
     */
    public function with(IngredientNeed $need): self
    {
        return new self(
            name: $this->name,
            quantity: self::total($this->quantity, $need->quantity),
            unit: $this->unit,
            section: $this->section === GrocerySection::UNSECTIONED ? $need->section() : $this->section,
            pantryBasic: $this->pantryBasic && $need->pantryBasic,
            meals: $this->mealsWith($need->meal),
            id: $this->id,
            checked: $this->checked,
            checkedAt: $this->checkedAt,
        );
    }

    public function mergeKey(): string
    {
        return MergeKey::of($this->name, $this->unit);
    }

    /**
     * The same identity, in the form the row stores it (MM-23).
     *
     * `plan_grocery_items` is unique on `(plan_id, merge_key)`, so this is what
     * stops one thing becoming two rows and two checkboxes.
     */
    public function storedKey(): string
    {
        return MergeKey::stored($this->mergeKey());
    }

    /**
     * The same line, read in a different part of the store.
     *
     * For the one case that needs it: two measures of one ingredient belong under
     * one heading, even though they cannot be added together (GroceryMerge).
     */
    public function inSection(string $section): self
    {
        if ($section === $this->section) {
            return $this;
        }

        return new self(
            name: $this->name,
            quantity: $this->quantity,
            unit: $this->unit,
            section: $section,
            pantryBasic: $this->pantryBasic,
            meals: $this->meals,
            id: $this->id,
            checked: $this->checked,
            checkedAt: $this->checkedAt,
        );
    }

    /**
     * How much to buy, as the list reads it: "2 lb", "3", or nothing at all when
     * nobody said how much.
     *
     * A unit on its own is not an amount. "Ground beef — lb" is not a thing to
     * write on a list, and it is what a measure with no number would otherwise
     * read as — in MM-9's post to the household chat as well. A household that
     * wants "a splash of soy" on the list can say so in the ingredient's name,
     * where it is a name and not arithmetic.
     */
    public function amount(): ?string
    {
        if ($this->quantity === null) {
            return null;
        }

        return trim(self::number($this->quantity).' '.(string) $this->unit);
    }

    /**
     * The whole line in one string: "Ground beef — 2 lb".
     */
    public function describe(): string
    {
        $amount = $this->amount();

        return $amount === null ? $this->name : $this->name.' — '.$amount;
    }

    /**
     * The meals that want it, as one phrase: "Beef Bulgogi, White Boy Tacos".
     */
    public function mealsLabel(): ?string
    {
        return $this->meals === [] ? null : implode(', ', $this->meals);
    }

    public function isMeasured(): bool
    {
        return $this->quantity !== null;
    }

    /**
     * Does this line ask for more than the one it replaces did?
     *
     * What a tick turns on: "we have enough of this" stops being true when the
     * plan starts asking for more (MM-7, docs/decisions.md).
     *
     * Two amounts compare directly. Where one of them is missing — and both the
     * amount and the unit are optional on an ingredient — the question is whether
     * *another dinner* has started wanting the line: an amount appearing for the
     * same single meal is a recipe getting more specific, but an amount appearing
     * alongside a second meal is a bigger shop hiding behind a tick. An unmeasured
     * need adds nothing to the total, so "some beef" ticked for Monday would
     * otherwise still read as crossed off when Tuesday asked for a pound.
     */
    public function asksForMoreThan(self $other): bool
    {
        if ($this->quantity !== null && $other->quantity !== null) {
            // A hundredth: the column stores two decimal places, so anything
            // smaller is rounding rather than a bigger shop.
            return $this->quantity > $other->quantity + 0.005;
        }

        return $this->quantity !== null
            && $other->quantity === null
            && count($this->meals) > count($other->meals);
    }

    /**
     * The structured form a page or a Slack post renders (MM-8, MM-9).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'amount' => $this->amount(),
            'section' => $this->section,
            'pantry_basic' => $this->pantryBasic,
            'meals' => $this->meals,
            'checked' => $this->checked,
            'checked_at' => $this->checkedAt?->toIso8601String(),
        ];
    }

    /**
     * The attributes of the stored row this line is.
     *
     * The amount is rounded to what the column can hold, so a sum that came out
     * as 0.30000000000000004 in binary floating point does not read as a changed
     * line on the next rebuild — which would stamp the plan and have MM-8 warn
     * the household about a list that is identical.
     *
     * These are the columns a line owns, and the only ones a rebuild writes: the
     * tick is the household's, and `created_at` says when the line first appeared
     * (MM-23).
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'name' => $this->name,
            // The line's identity, stored so the database can hold it to one row.
            'merge_key' => $this->storedKey(),
            'meals' => $this->meals,
            'quantity' => $this->quantity === null ? null : round($this->quantity, 2),
            'unit' => $this->unit,
            'store_section' => $this->section === GrocerySection::UNSECTIONED ? null : $this->section,
            'pantry_basic' => $this->pantryBasic,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function mealsWith(?string $meal): array
    {
        if ($meal === null || in_array($meal, $this->meals, true)) {
            return $this->meals;
        }

        return [...$this->meals, $meal];
    }

    /**
     * Two amounts added up, where either of them may be "nobody said".
     */
    private static function total(?float $running, ?float $extra): ?float
    {
        if ($running === null) {
            return $extra;
        }

        return $extra === null ? $running : $running + $extra;
    }

    /**
     * An amount the way a person writes it: "2", not "2.00", and "0.5" rather
     * than "0.50".
     */
    private static function number(float $quantity): string
    {
        $formatted = number_format($quantity, 2, '.', '');

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }
}
