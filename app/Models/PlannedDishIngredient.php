<?php

namespace App\Models;

use App\Enums\Unit;
use App\Enums\UnitGroup;
use App\Models\Concerns\HasQuantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $planned_dish_id
 * @property int $ingredient_id
 * @property numeric-string $quantity
 * @property Unit $unit
 * @property UnitGroup $unit_group
 * @property int $position
 */
#[Fillable(['planned_dish_id', 'ingredient_id', 'quantity', 'unit', 'unit_group', 'position'])]
class PlannedDishIngredient extends Model
{
    use HasQuantity;

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'unit_group' => UnitGroup::class,
            'quantity' => 'decimal:3',
        ];
    }
}
