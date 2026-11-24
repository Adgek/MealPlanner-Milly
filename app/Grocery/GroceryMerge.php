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

        return $this->group(array_values($lines));
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
}
