<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One household setting, stored as a key and a JSON value.
 *
 * This is the raw row. Prefer App\Settings\HouseholdSettings for reading, because
 * it knows each setting's default and returns a typed value instead of whatever
 * JSON happens to be in the column.
 *
 * @property string $key
 * @property mixed $value
 *
 * @method static SettingFactory factory($count = null, $state = [])
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            // 'json' keeps strings, numbers, booleans and arrays all usable in
            // one column, so a new setting never needs a migration.
            'value' => 'json',
        ];
    }

    /**
     * Read a setting, falling back to $default when the row is absent or null.
     */
    public static function read(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    /**
     * Create or update a setting.
     */
    public static function write(string $key, mixed $value): static
    {
        return static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Every setting, keyed by name — one query for the Settings page.
     *
     * @return Collection<string, mixed>
     */
    public static function map(): Collection
    {
        return static::query()->pluck('value', 'key');
    }
}
