<?php

use App\Grocery\GroceryLine;
use App\Grocery\MergeKey;

/*
 * What two ingredients have to share to be one line — and the same thing in the
 * form a row stores, which is what the unique index on
 * (plan_id, merge_key) holds the database to (MM-23).
 *
 * These two must agree on every input, because they are the same question asked
 * twice: the merge folds two needs into one line, and the index refuses to store
 * that line twice. If they could drift, the database would refuse lines the
 * planner thinks are different, or accept lines it thinks are the same.
 *
 * Unit tests: no framework, no database (AGENTS.md).
 */

/**
 * The stored form of a need's own identity, the way a row gets it.
 */
function theStoredKeyOf(string $name, ?string $unit = null): string
{
    return (new GroceryLine(name: $name, unit: $unit))->storedKey();
}

it('stores the merge key and nothing else', function () {
    // A function of the key, so the two cannot be changed apart.
    expect(theStoredKeyOf('Ground beef', 'lb'))
        ->toBe(MergeKey::stored(MergeKey::of('Ground beef', 'lb')));
});

it('gives two needs that merge the same stored key', function () {
    $one = aNeed('Ground beef', 1, 'lb', 'Meat');
    $other = aNeed('Ground beef', 2, 'lb', 'Produce');

    expect($one->mergeKey())->toBe($other->mergeKey())
        ->and(theStoredKeyOf($one->name, $one->unit))->toBe(theStoredKeyOf($other->name, $other->unit));
});

it('gives two needs that do not merge different stored keys', function () {
    // A pound is not 500 g, and nothing here converts between units (MM-7).
    expect(theStoredKeyOf('Ground beef', 'lb'))->not->toBe(theStoredKeyOf('Ground beef', 'g'))
        ->and(theStoredKeyOf('Ground beef', 'lb'))->not->toBe(theStoredKeyOf('Ground pork', 'lb'));
});

it('counts no unit, an empty unit and a unit of spaces as one thing', function () {
    // The reason the key is stored at all. `unit` is nullable, MySQL counts nulls
    // as distinct in a unique index, and a need with no unit is ordinary rather
    // than an edge — "a splash of soy", a kid food sold in packs. A null unit has
    // to be an identity like any other, not a hole in the index.
    $noUnit = theStoredKeyOf('Soy sauce');

    expect(theStoredKeyOf('Soy sauce', null))->toBe($noUnit)
        ->and(theStoredKeyOf('Soy sauce', ''))->toBe($noUnit)
        ->and(theStoredKeyOf('Soy sauce', '   '))->toBe($noUnit)
        // And it is still a key, not an absence of one.
        ->and($noUnit)->not->toBe('');
});

it('ignores the case and the spacing of a name and a unit', function () {
    $beef = theStoredKeyOf('Ground beef', 'lb');

    expect(theStoredKeyOf('ground beef', 'lb'))->toBe($beef)
        ->and(theStoredKeyOf('Ground  beef', 'lb'))->toBe($beef)
        ->and(theStoredKeyOf('  GROUND BEEF  ', 'lb'))->toBe($beef)
        ->and(theStoredKeyOf('Ground beef', ' LB '))->toBe($beef);
});

it('keeps two names apart that only an accent tells apart', function () {
    // The database would not, on its own: the table's collation is
    // utf8mb4_unicode_ci, under which 'cafe' = 'café' is true. A unique index on
    // the names themselves would refuse the second of these two lines, and the
    // household would lose one with nothing to show why. Hex digits in an
    // ascii_bin column compare the way the merge does.
    expect(theStoredKeyOf('Cafe au lait', 'cup'))->not->toBe(theStoredKeyOf('Café au lait', 'cup'));
});

it('fits the column whatever the household writes', function () {
    $long = theStoredKeyOf(str_repeat('Ground beef ', 40), str_repeat('pound ', 40));

    expect($long)->toHaveLength(MergeKey::STORED_WIDTH)
        ->and(theStoredKeyOf('Ground beef', 'lb'))->toHaveLength(MergeKey::STORED_WIDTH)
        // Ascii, and nothing a column would mangle: the key itself holds a NUL.
        ->and($long)->toMatch('/^[0-9a-f]+$/')
        ->and(MergeKey::of('Ground beef', 'lb'))->toContain("\0");
});
