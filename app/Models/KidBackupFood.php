<?php

namespace App\Models;

use Database\Factories\KidBackupFoodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A guaranteed-safe backup food to keep stocked for the kids (AppPlan.md, section 3).
 *
 * @property string $name
 * @property float|null $quantity
 * @property string|null $unit
 *
 * @method static KidBackupFoodFactory factory($count = null, $state = [])
 */
class KidBackupFood extends Model
{
    /** @use HasFactory<KidBackupFoodFactory> */
    use HasFactory;

    /**
     * Laravel treats "food" as uncountable and would look for `kid_backup_food`.
     */
    protected $table = 'kid_backup_foods';

    protected $fillable = [
        'name',
        'quantity',
        'unit',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
        ];
    }
}
