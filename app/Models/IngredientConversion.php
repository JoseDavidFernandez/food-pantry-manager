<?php

namespace App\Models;

use App\Enums\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A conversion that only applies to one food (RN-40): 1 `unit` = `factor` `to_unit`.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property Unit $unit
 * @property numeric-string $factor
 * @property Unit $to_unit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ingredient $ingredient
 */
#[Fillable(['ingredient_id', 'unit', 'factor', 'to_unit'])]
class IngredientConversion extends Model
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
        return [
            'unit' => Unit::class,
            'to_unit' => Unit::class,
            'factor' => 'decimal:6',
        ];
    }
}
