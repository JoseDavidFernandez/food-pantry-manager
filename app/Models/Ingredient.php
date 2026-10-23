<?php

namespace App\Models;

use App\Enums\IngredientCategory;
use App\Enums\Unit;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Normalized ingredient of a household (RN-03).
 *
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property string $normalized_name
 * @property Unit|null $default_unit
 * @property Unit|null $purchase_unit
 * @property numeric-string|null $restock_quantity
 * @property Unit|null $restock_unit
 * @property string|null $restock_mode
 * @property CarbonImmutable|null $restock_since
 * @property IngredientCategory|null $category
 * @property string|null $image_path
 * @property string|null $image_provider
 * @property string|null $image_source_url
 * @property string|null $image_author
 * @property string|null $image_license
 * @property string|null $image_license_url
 * @property Carbon|null $image_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'household_id', 'name', 'normalized_name', 'default_unit', 'purchase_unit', 'restock_quantity', 'restock_unit', 'restock_mode', 'restock_since', 'category', 'image_path', 'image_provider',
    'image_source_url', 'image_author', 'image_license', 'image_license_url', 'image_updated_at',
])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use BelongsToHousehold, HasFactory;

    /**
     * @return HasMany<IngredientAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(IngredientAlias::class);
    }

    /**
     * Its own unit conversions (RN-40).
     *
     * @return HasMany<IngredientConversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(IngredientConversion::class);
    }

    /**
     * @return HasMany<InventoryItem, $this>
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * @return HasMany<RecipeIngredient, $this>
     */
    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    /**
     * @return HasMany<PlannedDishIngredient, $this>
     */
    public function plannedDishIngredients(): HasMany
    {
        return $this->hasMany(PlannedDishIngredient::class);
    }

    /**
     * Category and image as the pages show them. Without an image the page shows the icon of the
     * category (or a generic one): no external request is made to display it.
     *
     * @return array{category: string|null, category_label: string, image_url: string|null, image_credit: array{provider: string|null, author: string|null, license: string|null, license_url: string|null, source_url: string|null}|null}
     */
    public function visualPayload(): array
    {
        return [
            'category' => $this->category?->value,
            'category_label' => $this->category?->label() ?? 'Sin clasificar',
            'image_url' => $this->image_path === null ? null : route('ingredients.image.show', [
                'ingredient' => $this->id,
                'v' => $this->image_updated_at?->timestamp,
            ]),
            'image_credit' => $this->image_path === null ? null : [
                'provider' => $this->image_provider,
                'author' => $this->image_author,
                'license' => $this->image_license,
                'license_url' => $this->image_license_url,
                'source_url' => $this->image_source_url,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_unit' => Unit::class,
            'purchase_unit' => Unit::class,
            'restock_quantity' => 'decimal:3',
            'restock_unit' => Unit::class,
            'restock_since' => 'immutable_datetime',
            'category' => IngredientCategory::class,
            'image_updated_at' => 'datetime',
        ];
    }
}
