<?php

namespace App\Grocery;

use App\Settings\HouseholdSettings;

/**
 * The order the household walks their store.
 *
 * Store sections are free text, because they depend on the shop (MM-3) — and so
 * does the order you meet them in, which makes it a household value rather than
 * something to hard-code (AGENTS.md). It is the `store_section_order` setting: a
 * list of section names, longest-standing habit first.
 *
 * With nothing set — which is how a fresh database starts, because nobody can
 * guess a household's store — sections come out alphabetically, which at least
 * puts the same section in the same place every week. Sections the household
 * hasn't named follow the ones they have, and the odds and ends with no section
 * at all come last.
 */
final class GrocerySectionOrder
{
    /**
     * The household's sections, normalised, in their order.
     *
     * @var array<int, string>
     */
    private array $preferred;

    /**
     * @param  array<int, string>  $preferred  section names in the order the store is walked
     */
    public function __construct(array $preferred = [])
    {
        $names = [];

        foreach ($preferred as $name) {
            $normalised = MergeKey::normalise(is_string($name) ? $name : '');

            if ($normalised !== '' && ! in_array($normalised, $names, true)) {
                $names[] = $normalised;
            }
        }

        $this->preferred = $names;
    }

    public static function fromSettings(): self
    {
        return new self(HouseholdSettings::storeSectionOrder());
    }

    /**
     * The sections of a list, in the order they should be read.
     *
     * @param  array<int, string>  $sections
     * @return array<int, string>
     */
    public function sort(array $sections): array
    {
        $unique = [];

        // One entry per aisle, not per spelling: "Produce" and "produce" are the
        // same trip round the store, and the first spelling is the one kept.
        foreach (array_map('strval', $sections) as $section) {
            $unique[MergeKey::normalise($section)] ??= $section;
        }

        $sorted = array_values($unique);

        usort($sorted, fn (string $a, string $b) => $this->rank($a) <=> $this->rank($b));

        return $sorted;
    }

    /**
     * Where a section sorts: the household's own order first, then everything
     * else alphabetically, then the unsectioned leftovers.
     *
     * @return array<int, int|string>
     */
    private function rank(string $section): array
    {
        $name = MergeKey::normalise($section);
        $chosen = array_search($name, $this->preferred, true);

        if ($chosen !== false) {
            return [0, $chosen, ''];
        }

        if ($name === MergeKey::normalise(GrocerySection::UNSECTIONED)) {
            return [2, 0, ''];
        }

        return [1, 0, $name];
    }
}
