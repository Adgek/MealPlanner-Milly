<?php

namespace App\Models;

use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something a recipe or a kitchen project needs from the store.
 *
 * @property string $name
 * @property float|null $quantity
 * @property string|null $unit
 * @property string|null $store_section
 * @property bool $pantry_basic
 *
 * @method static IngredientFactory factory($count = null, $state = [])
 */
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'quantity',
        'unit',
        'store_section',
        'pantry_basic',
    ];

    protected function casts(): array
    {
        return [
            // Float rather than Laravel's 'decimal', which hands back a string:
            // the grocery list adds these up ("1 lb + 1 lb" -> "2 lb").
            'quantity' => 'float',
            'pantry_basic' => 'boolean',
        ];
    }

    /**
     * The Recipe or KitchenProject that needs it.
     *
     * @return MorphTo<Model, $this>
     */
    public function ingredientable(): MorphTo
    {
        return $this->morphTo();
    }
}
