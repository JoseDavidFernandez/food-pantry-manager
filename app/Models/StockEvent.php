<?php

namespace App\Models;

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Enums\UnitGroup;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something that happened to a stock row (RN-44): an addition, a count or a move. The row is
 * identified by its ingredient, location and compatible group, like in `inventory_items`.
 *
 * @property int $id
 * @property int $household_id
 * @property int $ingredient_id
 * @property string $kind
 * @property StorageLocation $location
 * @property UnitGroup $unit_group
 * @property StorageLocation|null $to_location
 * @property numeric-string|null $quantity
 * @property Unit|null $unit
 * @property CarbonImmutable $occurred_at
 * @property int|null $reception_item_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'ingredient_id', 'kind', 'location', 'unit_group', 'to_location', 'quantity', 'unit', 'occurred_at', 'reception_item_id'])]
class StockEvent extends Model
{
    public const ADD = 'add';

    public const COUNT = 'count';

    public const MOVE = 'move';

    public function toQuantity(): ?Quantity
    {
        return $this->quantity === null || $this->unit === null || bccomp($this->quantity, '0', 3) <= 0
            ? null
            : Quantity::of($this->quantity, $this->unit);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location' => StorageLocation::class,
            'to_location' => StorageLocation::class,
            'unit_group' => UnitGroup::class,
            'unit' => Unit::class,
            'quantity' => 'decimal:3',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
