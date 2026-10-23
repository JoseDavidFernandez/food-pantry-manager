<?php

namespace App\Models;

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Enums\UnitGroup;
use App\Models\Concerns\BelongsToHousehold;
use App\Support\Quantity;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stock of an ingredient in a location, in one compatible unit group (RN-08).
 *
 * @property int $id
 * @property int $household_id
 * @property int $ingredient_id
 * @property StorageLocation $location
 * @property Unit $unit
 * @property UnitGroup $unit_group
 * @property numeric-string $quantity
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'ingredient_id', 'location', 'unit', 'unit_group', 'quantity', 'label'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use BelongsToHousehold, HasFactory;

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function toQuantity(): Quantity
    {
        return Quantity::of($this->quantity, $this->unit);
    }

    public function setQuantity(Quantity $quantity): void
    {
        $quantity = $quantity->storable();
        $this->quantity = $quantity->amount;
        $this->unit = $quantity->unit;
        $this->unit_group = $quantity->unit->group();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location' => StorageLocation::class,
            'unit' => Unit::class,
            'unit_group' => UnitGroup::class,
            'quantity' => 'decimal:3',
        ];
    }
}
