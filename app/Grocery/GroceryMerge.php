<?php

namespace App\Grocery;

/**
 * The merge: every ingredient the window asks for, combined into one list.
 *
 * This is the part of MM-7 that will be wrong in a way nobody notices until
 * dinner, so it is a plain class over plain values with no database and no
 * framework behind it, and its rules are unit-tested one at a time (AGENTS.md).
 * The rules themselves live in MergeKey (what counts as the same thing) and
 * GroceryLine::with() (what happens when two needs are the same thing).
 */
final class GroceryMerge
{
    public function __construct(
        private readonly GrocerySectionOrder $order = new GrocerySectionOrder,
    ) {}

    /**
     * The merge as the household has it: their own order round the store.
     */
    public static function fromSettings(): self
    {
        return new self(GrocerySectionOrder::fromSettings());
    }

    /**
     * Needs in, one list out.
     *
     * Needs are folded in the order they are given, so the first night of the
     * window decides a line's spelling and its section.
     *
     * @param  array<int, IngredientNeed>  $needs
     */
    public function merge(array $needs): GroceryList
    {
        $lines = [];

        foreach ($needs as $need) {
            $key = $need->mergeKey();

            $lines[$key] = isset($lines[$key])
                ? $lines[$key]->with($need)
                : GroceryLine::from($need);
        }

        return $this->group($this->keepNamesTogether(array_values($lines)));
    }

    /**
     * Lines that are already merged, grouped and ordered as a list.
     *
     * What the stored checklist comes back through, so a page gets the same shape
     * either way.
     *
     * @param  array<int, GroceryLine>  $lines
     */
    public function group(array $lines): GroceryList
    {
        return GroceryList::of($lines, $this->order);
    }

    /**
     * Two measures of one ingredient, read under one heading.
     *
     * A pound of beef and 500 g of beef stay two lines, because nothing here
     * converts between units — but they are one thing to buy, so every line for a
     * name takes the first real store section any of them gave.
     *
     * This is what the kid off-ramp needs most. The inventory is stocked in packs
     * — a bag of nuggets, a loaf of bread — and no recipe measures tortillas in
     * bags, so a kid backup food usually *cannot* merge into the recipe line that
     * wants the same item. Left in its own section it would be the same thing
     * written twice, two sections apart: bought twice, and ticked once.
     *
     * @param  array<int, GroceryLine>  $lines
     * @return array<int, GroceryLine>
     */
    private function keepNamesTogether(array $lines): array
    {
        $sections = [];

        foreach ($lines as $line) {
            if ($line->section !== GrocerySection::UNSECTIONED) {
                $sections[MergeKey::normalise($line->name)] ??= $line->section;
            }
        }

        return array_map(
            fn (GroceryLine $line) => $line->inSection(
                $sections[MergeKey::normalise($line->name)] ?? $line->section,
            ),
            $lines,
        );
    }
}
