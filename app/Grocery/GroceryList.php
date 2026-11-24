<?php

namespace App\Grocery;

/**
 * One combined shopping list for a window: what to buy, grouped by store
 * section, with the pantry basics in a short "check you have" section at the end
 * (AppPlan.md, section 3).
 *
 * This is the structured output MM-8's page renders and MM-9's post is written
 * from. It is a plain value: the same shape whether it was just computed or read
 * back off the stored checklist, so a page can render either.
 */
final class GroceryList
{
    /**
     * @param  array<int, GrocerySection>  $sections
     */
    private function __construct(
        public readonly array $sections = [],
        /** The short section at the end; null when nothing on the list is a basic. */
        public readonly ?GrocerySection $pantryBasics = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * A list from lines that have already been merged.
     *
     * Lines are grouped by section, the sections ordered the way the household
     * walks the store (GrocerySectionOrder), and each section's lines sorted by
     * name so the list reads the same every week.
     *
     * @param  array<int, GroceryLine>  $lines
     */
    public static function of(array $lines, ?GrocerySectionOrder $order = null): self
    {
        $order ??= new GrocerySectionOrder;

        $basics = [];
        $bySection = [];

        foreach ($lines as $line) {
            if ($line->pantryBasic) {
                $basics[] = $line;

                continue;
            }

            $bySection[$line->section][] = $line;
        }

        $sections = [];

        foreach ($order->sort(array_keys($bySection)) as $name) {
            $sections[] = new GrocerySection($name, self::byName($bySection[$name]));
        }

        return new self(
            $sections,
            $basics === [] ? null : new GrocerySection(GrocerySection::PANTRY_BASICS, self::byName($basics)),
        );
    }

    /**
     * Every line, in the order the list is read: the store sections, then the
     * pantry basics.
     *
     * @return array<int, GroceryLine>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->allSections() as $section) {
            foreach ($section->lines as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The store sections and the "check you have" section, in reading order.
     *
     * @return array<int, GrocerySection>
     */
    public function allSections(): array
    {
        return $this->pantryBasics === null
            ? $this->sections
            : [...$this->sections, $this->pantryBasics];
    }

    /**
     * @return array<int, string>
     */
    public function sectionNames(): array
    {
        return array_map(fn (GrocerySection $section) => $section->name, $this->allSections());
    }

    /**
     * The line for a merge key, if the list has one.
     */
    public function line(string $mergeKey): ?GroceryLine
    {
        foreach ($this->lines() as $line) {
            if ($line->mergeKey() === $mergeKey) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The line for a name and unit, which is how a person asks.
     */
    public function lineFor(string $name, ?string $unit = null): ?GroceryLine
    {
        return $this->line(MergeKey::of($name, $unit));
    }

    public function count(): int
    {
        return count($this->lines());
    }

    public function checkedCount(): int
    {
        return count(array_filter($this->lines(), fn (GroceryLine $line) => $line->checked));
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [];
    }

    /**
     * The whole list as nested arrays, for a page, a payload or a snapshot test.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sections' => array_map(fn (GrocerySection $section) => $section->toArray(), $this->sections),
            'pantry_basics' => $this->pantryBasics?->toArray(),
            'count' => $this->count(),
            'checked' => $this->checkedCount(),
        ];
    }

    /**
     * @param  array<int, GroceryLine>  $lines
     * @return array<int, GroceryLine>
     */
    private static function byName(array $lines): array
    {
        usort(
            $lines,
            fn (GroceryLine $a, GroceryLine $b) => [MergeKey::normalise($a->name), MergeKey::normalise($a->unit)]
                <=> [MergeKey::normalise($b->name), MergeKey::normalise($b->unit)],
        );

        return $lines;
    }
}
