<?php

namespace App\Enums;

/**
 * General category of a catalog ingredient (DATA-MODEL §2). It classifies WHAT the product is,
 * independently of WHERE it is stored (StorageLocation). Stock inherits it from its ingredient.
 * An ingredient may have none yet ("Sin clasificar"): it is never guessed from the name.
 */
enum IngredientCategory: string
{
    case Fruit = 'fruit';
    case Vegetables = 'vegetables';
    case Meat = 'meat';
    case Fish = 'fish';
    case DairyEggs = 'dairy_eggs';
    case BakeryCereals = 'bakery_cereals';
    case PastaRiceLegumes = 'pasta_rice_legumes';
    case ColdCuts = 'cold_cuts';
    case Preserves = 'preserves';
    case SoupsBroths = 'soups_broths';
    case Drinks = 'drinks';
    case SaucesOils = 'sauces_oils';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fruit => 'Frutas',
            self::Vegetables => 'Verduras y hortalizas',
            self::Meat => 'Carne y aves',
            self::Fish => 'Pescado y marisco',
            self::DairyEggs => 'Lácteos y huevos',
            self::BakeryCereals => 'Panadería y cereales',
            self::PastaRiceLegumes => 'Pasta, arroz y legumbres',
            self::ColdCuts => 'Embutidos',
            self::Preserves => 'Conservas y envasados',
            self::SoupsBroths => 'Caldos, cremas y sopas',
            self::Drinks => 'Bebidas',
            self::SaucesOils => 'Salsas, aceites y condimentos',
            self::Other => 'Otros',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => [
            'value' => $category->value,
            'label' => $category->label(),
        ], self::cases());
    }
}
