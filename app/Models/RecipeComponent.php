<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A preparation included in a recipe (RN-43): `servings` servings of another recipe for the
 * base servings of this one.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $component_recipe_id
 * @property int $servings
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Recipe $component
 * @property-read Recipe $recipe
 */
#[Fillable(['recipe_id', 'component_recipe_id', 'servings', 'position'])]
class RecipeComponent extends Model
{
    /**
     * The recipe that includes the preparation.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'component_recipe_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['servings' => 'integer', 'position' => 'integer'];
    }
}
