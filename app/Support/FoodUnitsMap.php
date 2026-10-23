<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Ingredient;

/**
 * The units of every food of a household that has its own conversions or a purchase unit
 * (RN-40, RN-41), loaded once per calculation. Every other food uses the universal groups.
 */
final class FoodUnitsMap
{
    /**
     * @param  array<int, FoodUnits>  $foods
     */
    private function __construct(private readonly array $foods) {}

    public static function forHousehold(Household $household): self
    {
        $foods = [];

        $ingredients = $household->ingredients()
            ->where(fn ($query) => $query->whereNotNull('purchase_unit')->orWhereHas('conversions'))
            ->with('conversions')
            ->get();

        foreach ($ingredients as $ingredient) {
            /** @var Ingredient $ingredient */
            $foods[$ingredient->id] = FoodUnits::from($ingredient->conversions, $ingredient->purchase_unit);
        }

        return new self($foods);
    }

    public function for(?int $ingredientId): FoodUnits
    {
        return $ingredientId === null ? FoodUnits::none() : ($this->foods[$ingredientId] ?? FoodUnits::none());
    }

    /**
     * The quantity in the unit it is compared in, with the key of its line: the food and the
     * canonical group (the same key as before for foods without conversions).
     *
     * @return array{0: string, 1: Quantity}
     */
    public function keyed(int $ingredientId, Quantity $quantity): array
    {
        $normalized = $this->for($ingredientId)->normalize($quantity);

        return [$ingredientId.':'.$normalized->unit->group()->value, $normalized];
    }
}
