<?php

namespace App\Support;

/**
 * Stock still unassigned while dishes are taken in order (RN-42): each dish takes what it needs
 * from what the dishes before it left, so the same stock is never counted for two dishes.
 * Keys and quantities are in the canonical unit of each food (FoodUnitsMap::keyed).
 */
final class StockAllocation
{
    /**
     * @param  array<string, Quantity>  $remaining
     */
    public function __construct(private array $remaining) {}

    public static function none(): self
    {
        return new self([]);
    }

    public function available(string $key): ?Quantity
    {
        return $this->remaining[$key] ?? null;
    }

    public function covers(string $key, Quantity $quantity): bool
    {
        $available = $this->available($key);

        return $available !== null && $available->compareTo($quantity) >= 0;
    }

    public function take(string $key, Quantity $quantity): void
    {
        $available = $this->available($key);

        if ($available === null) {
            return;
        }

        $left = $available->minus($quantity);

        if ($left === null) {
            unset($this->remaining[$key]);
        } else {
            $this->remaining[$key] = $left;
        }
    }
}
