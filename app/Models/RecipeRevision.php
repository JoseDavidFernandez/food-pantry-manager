<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The composition a recipe had until `valid_until` (RN-45): dishes whose reference time is at
 * or before that moment keep using it for the estimated stock.
 *
 * @property int $id
 * @property int $recipe_id
 * @property CarbonImmutable $valid_until
 * @property int $base_servings
 * @property list<array{ingredient_id: int, quantity: string, unit: string, alternative_group: int|null, position: int}> $ingredients
 * @property list<array{component_recipe_id: int, servings: int, position: int}> $components
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['recipe_id', 'valid_until', 'base_servings', 'ingredients', 'components'])]
class RecipeRevision extends Model
{
    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_until' => 'immutable_datetime',
            'base_servings' => 'integer',
            'ingredients' => 'array',
            'components' => 'array',
        ];
    }
}
