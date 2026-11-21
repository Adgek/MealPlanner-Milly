<?php

namespace App\Models;

use App\Settings\HouseholdSettings;
use Database\Factories\FreezerItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the freezer stash: what it is, and how many portions are left.
 *
 * @property string $name
 * @property int $portions_left
 * @property int $thaw_lead_time_hours
 * @property string|null $kid_off_ramp
 *
 * @method static FreezerItemFactory factory($count = null, $state = [])
 */
class FreezerItem extends Model
{
    /** @use HasFactory<FreezerItemFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'portions_left',
        'thaw_lead_time_hours',
        'kid_off_ramp',
    ];

    protected function casts(): array
    {
        return [
            'portions_left' => 'integer',
            'thaw_lead_time_hours' => 'integer',
        ];
    }

    /**
     * Is there anything left to plan a night around?
     */
    public function isAvailable(): bool
    {
        return $this->portions_left > 0;
    }

    /**
     * Has the stash of this item dropped to the level worth warning about (MM-12)?
     */
    public function isRunningLow(): bool
    {
        return $this->portions_left <= HouseholdSettings::lowFreezerStashPortions();
    }

    /**
     * The kitchen projects that restock it.
     *
     * @return HasMany<KitchenProject, $this>
     */
    public function kitchenProjects(): HasMany
    {
        return $this->hasMany(KitchenProject::class, 'produces_freezer_item_id');
    }

    /**
     * @return HasMany<PlanNight, $this>
     */
    public function planNights(): HasMany
    {
        return $this->hasMany(PlanNight::class);
    }
}
