<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Reusable recipe with base servings (RN-12). Referenced recipes are archived, not deleted (RN-14).
 *
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property int $base_servings
 * @property string|null $instructions
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'name', 'base_servings', 'instructions', 'archived_at'])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use BelongsToHousehold, HasFactory;

    /**
     * @return HasMany<RecipeIngredient, $this>
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    /**
     * Preparations included in this recipe (RN-43), in order.
     *
     * @return HasMany<RecipeComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class)->orderBy('position');
    }

    /**
     * Earlier compositions, for the dishes that already took place with them (RN-45).
     *
     * @return HasMany<RecipeRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(RecipeRevision::class)->orderBy('valid_until');
    }

    /**
     * Recipes that include this one as a preparation.
     *
     * @return HasMany<RecipeComponent, $this>
     */
    public function usedIn(): HasMany
    {
        return $this->hasMany(RecipeComponent::class, 'component_recipe_id');
    }

    /**
     * @return HasMany<PlannedDish, $this>
     */
    public function plannedDishes(): HasMany
    {
        return $this->hasMany(PlannedDish::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<Recipe>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_servings' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
