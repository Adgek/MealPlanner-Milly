<?php

namespace App\Grocery;

/**
 * What two ingredients have to share to become one line of the grocery list:
 * the name and the unit, both normalised.
 *
 * The rule is deliberately narrow (docs/decisions.md, MM-7):
 *
 *   - **the name** is compared with its case and its spacing ignored, so
 *     "Ground beef", "ground beef" and "Ground  beef" are one thing;
 *   - **the unit** has to match exactly, after the same normalising. Nothing here
 *     converts between units, so "1 lb" and "500 g" stay two lines. Guessing that
 *     a pound is 454 g would be inventing household data, and being wrong about it
 *     at the store is worse than two lines on a list somebody reads anyway.
 *
 * MM-11's bulk-buy split notes and MM-12's kitchen-project ingredients join the
 * same list, so they inherit this key rather than inventing their own.
 *
 * `stored()` is the same key in the form a column holds, so the database can
 * police it (MM-23).
 */
final class MergeKey
{
    /**
     * The width of the `plan_grocery_items.merge_key` column: a SHA-256 as hex.
     */
    public const STORED_WIDTH = 64;

    /**
     * The key one ingredient merges on.
     */
    public static function of(string $name, ?string $unit = null): string
    {
        // NUL rather than a printable separator, so no unit can forge a key.
        return self::normalise($name)."\0".self::normalise($unit);
    }

    /**
     * The same key in the form a row stores and a unique index can police.
     *
     * `plan_grocery_items.merge_key` is this, with `unique (plan_id, merge_key)`
     * over it, so the database itself refuses a second row for a line the window
     * has already got (MM-23). It takes the merge key rather than a name and a
     * unit, so the stored identity is a function of the one above and the two
     * cannot drift: what the merge folds into one line is what the index refuses
     * to store twice.
     *
     * Why the key is hashed rather than stored as it reads:
     *
     *   - **nothing is null.** A need with no unit is an ordinary case — "a splash
     *     of soy", a kid food sold in packs — and MySQL counts nulls as distinct
     *     in a unique index, so the obvious index on `(plan_id, name, unit)` would
     *     let two of those through. `of()` already normalises "no unit" to the
     *     empty string, so here it is one identity like any other;
     *   - **the database compares it the way PHP does.** The table's collation is
     *     `utf8mb4_unicode_ci`, under which `'cafe' = 'café'` is true, so a plain
     *     text key would have the index refuse two lines the merge calls different
     *     — and the household would lose a line with nothing to show why. Hex
     *     digits in an `ascii_bin` column compare byte for byte, which is what
     *     `of()` means by equal;
     *   - the key itself carries a NUL separator and two names of any length,
     *     while this is 64 ascii characters whatever the household writes.
     */
    public static function stored(string $mergeKey): string
    {
        return hash('sha256', $mergeKey);
    }

    /**
     * The form two names (or units, or store sections) are compared in.
     */
    public static function normalise(?string $value): string
    {
        return mb_strtolower(self::tidy($value));
    }

    /**
     * The form a name is kept in for reading: the household's own capitalisation,
     * with the whitespace tidied up.
     */
    public static function tidy(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
