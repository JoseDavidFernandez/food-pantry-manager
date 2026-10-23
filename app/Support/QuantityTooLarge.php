<?php

namespace App\Support;

use RuntimeException;

/**
 * A quantity (usually the result of adding two) does not fit the DECIMAL(12,3) columns.
 * Thrown before saving, so the surrounding transaction is rolled back and nothing changes.
 */
class QuantityTooLarge extends RuntimeException
{
    public function __construct(Quantity $quantity)
    {
        parent::__construct("La cantidad resultante ({$quantity->format()}) supera el máximo que se puede guardar (999.999.999,999). No se ha guardado nada.");
    }
}
