<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The option a planned dish uses for a group of alternative ingredients (RN-42), confirmed or
 * changed by the user. It belongs to the dish, so every period that counts the dish uses it.
 *
 * @property int $id
 * @property int $planned_dish_id
 * @property int $recipe_id
 * @property int $alternative_group
 * @property int $ingredient_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient|null $ingredient
 */
#[Fillable(['planned_dish_id', 'recipe_id', 'alternative_group', 'ingredient_id'])]
class PlannedDishChoice extends Model
{
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
        return ['alternative_group' => 'integer'];
    }
}
