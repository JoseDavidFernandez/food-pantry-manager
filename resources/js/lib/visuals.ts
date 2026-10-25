import type { Component } from 'vue';
import {
    Apple,
    Archive,
    Carrot,
    CircleHelp,
    Croissant,
    CupSoda,
    Droplet,
    Drumstick,
    Fish,
    Ham,
    Milk,
    Package,
    Refrigerator,
    ShoppingBasket,
    Snowflake,
    Wheat,
} from '@lucide/vue';

/**
 * Icons of the ingredient categories (DESIGN-SYSTEM §5). The icon is the visual fallback
 * when an ingredient has no image; the label always accompanies it (never the icon alone).
 */
const CATEGORY_ICONS: Record<string, Component> = {
    fruit: Apple,
    vegetables: Carrot,
    meat: Drumstick,
    fish: Fish,
    dairy_eggs: Milk,
    bakery_cereals: Croissant,
    pasta_rice_legumes: Wheat,
    cold_cuts: Ham,
    preserves: Package,
    drinks: CupSoda,
    sauces_oils: Droplet,
    other: ShoppingBasket,
};

/** Categories in their catalog order (the aisles of the shopping list follow it). */
export const CATEGORY_ORDER = Object.keys(CATEGORY_ICONS);

export function categoryIcon(category: string | null | undefined): Component {
    return (category && CATEGORY_ICONS[category]) || CircleHelp;
}

const LOCATION_ICONS: Record<string, Component> = {
    fridge: Refrigerator,
    freezer: Snowflake,
    pantry: Archive,
};

export function locationIcon(location: string): Component {
    return LOCATION_ICONS[location] ?? Archive;
}

/** Value used in filters for ingredients without a category. */
export const UNCATEGORIZED = 'none';
