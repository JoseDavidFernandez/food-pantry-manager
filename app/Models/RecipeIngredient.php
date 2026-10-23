<?php

namespace App\Models;

use App\Enums\Unit;
use App\Enums\UnitGroup;
use App\Models\Concerns\HasQuantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantity of an ingredient for the recipe's base servings; never scaled in the database.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $ingredient_id
 * @property numeric-string $quantity
 * @property Unit $unit
 * @property UnitGroup $unit_group
 * @property string|null $note
 * @property int $position
 * @property int|null $alternative_group
 */
#[Fillable(['recipe_id', 'ingredient_id', 'quantity', 'unit', 'unit_group', 'note', 'position', 'alternative_group'])]
class RecipeIngredient extends Model
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
            'alternative_group' => 'integer',
        ];
    }
}
