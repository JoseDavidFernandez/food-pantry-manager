<?php

namespace App\Models;

use App\Enums\DishType;
use App\Enums\Meal;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Content of one menu slot: a saved recipe or a one-off dish (RN-16, RN-17).
 *
 * @property int $id
 * @property int $menu_id
 * @property int $day
 * @property Meal $meal
 * @property int $position
 * @property DishType $type
 * @property int|null $recipe_id
 * @property string|null $name
 * @property int $servings
 * @property CarbonImmutable|null $not_eaten_at
 * @property-read Recipe|null $recipe
 * @property-read Menu $menu
 */
#[Fillable(['menu_id', 'day', 'meal', 'position', 'type', 'recipe_id', 'name', 'servings', 'not_eaten_at'])]
class PlannedDish extends Model
{
    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Ingredients of a one-off dish, for its servings (not scaled).
     *
     * @return HasMany<PlannedDishIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(PlannedDishIngredient::class)->orderBy('position');
    }

    /**
     * Options chosen for the alternative ingredients of its recipe (RN-42).
     *
     * @return HasMany<PlannedDishChoice, $this>
     */
    public function choices(): HasMany
    {
        return $this->hasMany(PlannedDishChoice::class);
    }

    /**
     * «No comido» (RN-37): still shown in the menu, but its ingredients no longer count.
     */
    public function isNotEaten(): bool
    {
        return $this->not_eaten_at !== null;
    }

    /**
     * Its date at the reference time of its meal, in Europe/Madrid (RN-44): until then it is
     * pending; from then on it counts as eaten for the estimated stock.
     */
    public function referenceTime(): CarbonImmutable
    {
        // The date first, then the local time: correct across daylight saving changes.
        $date = $this->menu->week_start->addDays($this->day - 1)->format('Y-m-d');

        return CarbonImmutable::parse($date.' '.$this->meal->referenceTime(), Week::timezone());
    }

    public function displayName(): string
    {
        return $this->type === DishType::Recipe ? (string) $this->recipe?->name : (string) $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'position' => 'integer',
            'meal' => Meal::class,
            'type' => DishType::class,
            'servings' => 'integer',
            'not_eaten_at' => 'immutable_datetime',
        ];
    }
}
