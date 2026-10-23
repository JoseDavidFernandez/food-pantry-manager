<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Explicit synonym or plural of an ingredient (RN-04).
 *
 * @property int $id
 * @property int $household_id
 * @property int $ingredient_id
 * @property string $alias
 * @property string $normalized_alias
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'ingredient_id', 'alias', 'normalized_alias'])]
class IngredientAlias extends Model
{
    use BelongsToHousehold;

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
