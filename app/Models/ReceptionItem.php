<?php

namespace App\Models;

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Enums\UnitGroup;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision of a reception (DATA-MODEL §2): a list line received or not purchased,
 * or an unforeseen product (always received).
 *
 * @property int $id
 * @property int $reception_id
 * @property int|null $shopping_list_item_id
 * @property bool $is_unforeseen
 * @property int|null $ingredient_id
 * @property string $name
 * @property string $outcome
 * @property numeric-string|null $suggested_quantity
 * @property Unit|null $suggested_unit
 * @property numeric-string|null $quantity_received
 * @property Unit|null $unit
 * @property UnitGroup|null $unit_group
 * @property StorageLocation|null $location
 */
#[Fillable([
    'reception_id', 'shopping_list_item_id', 'is_unforeseen', 'ingredient_id', 'name', 'outcome',
    'suggested_quantity', 'suggested_unit', 'quantity_received', 'unit', 'unit_group', 'location',
])]
class ReceptionItem extends Model
{
    public const RECEIVED = 'received';

    public const NOT_PURCHASED = 'not_purchased';

    /**
     * @return BelongsTo<Reception, $this>
     */
    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    /**
     * @return BelongsTo<ShoppingListItem, $this>
     */
    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function isReceived(): bool
    {
        return $this->outcome === self::RECEIVED;
    }

    public function received(): ?Quantity
    {
        return $this->quantity_received !== null && $this->unit !== null ? Quantity::of($this->quantity_received, $this->unit) : null;
    }

    public function suggested(): ?Quantity
    {
        return $this->suggested_quantity !== null && $this->suggested_unit !== null
            ? Quantity::of($this->suggested_quantity, $this->suggested_unit)
            : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_unforeseen' => 'boolean',
            'suggested_quantity' => 'decimal:3',
            'suggested_unit' => Unit::class,
            'quantity_received' => 'decimal:3',
            'unit' => Unit::class,
            'unit_group' => UnitGroup::class,
            'location' => StorageLocation::class,
        ];
    }
}
