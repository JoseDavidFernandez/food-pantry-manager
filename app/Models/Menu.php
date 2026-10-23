<?php

namespace App\Models;

use App\Enums\MenuStatus;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Weekly menu of a household, Monday to Sunday (RN-15).
 *
 * @property int $id
 * @property int $household_id
 * @property CarbonImmutable $week_start
 * @property MenuStatus $status
 * @property Carbon|null $closed_at
 * @property Carbon|null $reopened_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'week_start', 'status'])]
class Menu extends Model
{
    use BelongsToHousehold;

    /**
     * @return HasMany<PlannedDish, $this>
     */
    public function dishes(): HasMany
    {
        return $this->hasMany(PlannedDish::class);
    }

    /**
     * @return HasOne<ShoppingList, $this>
     */
    public function shoppingList(): HasOne
    {
        return $this->hasOne(ShoppingList::class);
    }

    /** Lunch and dinner of seven days (RN-15). */
    public const SLOTS_PER_WEEK = 14;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_date',
            'status' => MenuStatus::class,
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
