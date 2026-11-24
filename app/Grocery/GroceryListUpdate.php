<?php

namespace App\Grocery;

/**
 * What making the list again did to it.
 *
 * The plan changes — a re-roll, a swap, a night off — and the list is made again.
 * Somebody may be holding the old one, so a caller needs to be able to say what
 * moved: MM-8 to flag it on the page, MM-9 to say it in the chat,
 * `milly:groceries` to print it.
 */
final class GroceryListUpdate
{
    /**
     * @param  array<int, GroceryLine>  $added  lines that were not on the list before
     * @param  array<int, GroceryLine>  $removed  lines nothing in the window wants any more, as they last read
     * @param  array<int, GroceryLine>  $unticked  lines whose tick stopped being true, because the plan now asks for more
     * @param  bool  $amended  whether any surviving line's amount, section or meals changed
     * @param  string|null  $leftAlone  why the list was not touched at all, when it wasn't
     */
    private function __construct(
        public readonly GroceryList $list,
        public readonly array $added = [],
        public readonly array $removed = [],
        public readonly array $unticked = [],
        public readonly bool $amended = false,
        public readonly ?string $leftAlone = null,
    ) {}

    /**
     * @param  array<int, GroceryLine>  $added
     * @param  array<int, GroceryLine>  $removed
     * @param  array<int, GroceryLine>  $unticked
     */
    public static function of(
        GroceryList $list,
        array $added = [],
        array $removed = [],
        array $unticked = [],
        bool $amended = false,
    ): self {
        return new self($list, $added, $removed, $unticked, $amended);
    }

    /**
     * The list was left exactly as it was, and this is why.
     */
    public static function leftAlone(GroceryList $list, string $because): self
    {
        return new self($list, leftAlone: $because);
    }

    /**
     * Did anything actually move?
     */
    public function changedAnything(): bool
    {
        return $this->added !== []
            || $this->removed !== []
            || $this->unticked !== []
            || $this->amended;
    }

    /**
     * One line a person can read: "2 added, 1 no longer needed, 1 un-ticked".
     */
    public function summary(): string
    {
        if ($this->leftAlone !== null) {
            return $this->leftAlone;
        }

        if (! $this->changedAnything()) {
            return 'No change: the list already matched the plan.';
        }

        $parts = array_filter([
            $this->added === [] ? null : self::phrase($this->added, 'added'),
            $this->removed === [] ? null : self::phrase($this->removed, 'no longer needed'),
            $this->unticked === [] ? null : self::phrase($this->unticked, 'un-ticked, because the plan now asks for more'),
        ]);

        return $parts === []
            ? 'Amounts changed.'
            : implode('. ', $parts).'.';
    }

    /**
     * @param  array<int, GroceryLine>  $lines
     */
    private static function phrase(array $lines, string $what): string
    {
        return sprintf(
            '%s %s',
            implode(', ', array_map(fn (GroceryLine $line) => $line->describe(), $lines)),
            $what,
        );
    }
}
