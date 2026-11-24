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
 */
final class MergeKey
{
    /**
     * The key one ingredient merges on.
     */
    public static function of(string $name, ?string $unit = null): string
    {
        // NUL rather than a printable separator, so no unit can forge a key.
        return self::normalise($name)."\0".self::normalise($unit);
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
