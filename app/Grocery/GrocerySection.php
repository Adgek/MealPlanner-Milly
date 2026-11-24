<?php

namespace App\Grocery;

/**
 * One part of the store, and what to buy in it.
 *
 * Section names are free text from the household's own recipes (MM-3), so the
 * only names in this class are the two the list itself needs: a home for items
 * nobody gave a section, and the short section at the end.
 */
final class GrocerySection
{
    /**
     * Where a line with no store section goes. Last of the shopping sections,
     * because it is not part of a walk round the store.
     */
    public const UNSECTIONED = 'Other';

    /**
     * The short section at the end: the pantry basics the household looks for at
     * the fridge instead of buying (AppPlan.md, section 3).
     */
    public const PANTRY_BASICS = 'Check you have';

    /**
     * @param  array<int, GroceryLine>  $lines
     */
    public function __construct(
        public readonly string $name,
        public readonly array $lines = [],
    ) {}

    public function count(): int
    {
        return count($this->lines);
    }

    public function checkedCount(): int
    {
        return count(array_filter($this->lines, fn (GroceryLine $line) => $line->checked));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'section' => $this->name,
            'lines' => array_map(fn (GroceryLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
