<?php

namespace App\Enums;

/**
 * Where an inventory item is stored (RN-08).
 */
enum StorageLocation: string
{
    case Fridge = 'fridge';
    case Freezer = 'freezer';
    case Pantry = 'pantry';

    public function label(): string
    {
        return match ($this) {
            self::Fridge => 'Frigorífico',
            self::Freezer => 'Congelador',
            self::Pantry => 'Despensa',
        };
    }

    /**
     * Where a purchase of a food goes by default (RN-46): chilled categories to the fridge,
     * everything else to the pantry. The user can always choose another location.
     */
    public static function defaultFor(?IngredientCategory $category): self
    {
        return match ($category) {
            IngredientCategory::Meat, IngredientCategory::Fish, IngredientCategory::DairyEggs, IngredientCategory::ColdCuts => self::Fridge,
            default => self::Pantry,
        };
    }

    /**
     * Order in which the estimated consumption is taken from the rows of a food (RN-44): what is
     * in the fridge is assumed to be used first, then the pantry, then the freezer.
     */
    public function consumptionOrder(): int
    {
        return match ($this) {
            self::Fridge => 0,
            self::Pantry => 1,
            self::Freezer => 2,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $location) => [
            'value' => $location->value,
            'label' => $location->label(),
        ], self::cases());
    }
}
