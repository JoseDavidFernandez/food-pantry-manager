<?php

namespace App\Models\Concerns;

use App\Enums\Unit;
use App\Support\Quantity;

/**
 * Rows with `quantity`, `unit` and `unit_group` columns (DATA-MODEL §4 and §5).
 *
 * @property numeric-string $quantity
 * @property Unit $unit
 */
trait HasQuantity
{
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
}
