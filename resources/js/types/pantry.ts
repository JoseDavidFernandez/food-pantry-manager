export type Option = {
    value: string;
    label: string;
};

export type UnitOption = Option & {
    group: string;
};

export type StorageLocation = 'fridge' | 'freezer' | 'pantry';

/** Attribution required by the licence of an ingredient image. */
export type ImageCredit = {
    provider: string | null;
    author: string | null;
    license: string | null;
    license_url: string | null;
    source_url: string | null;
};

/** Category and image of an ingredient as pages receive them (stock inherits them). */
export type IngredientVisual = {
    category: string | null;
    category_label: string;
    image_url: string | null;
    image_credit: ImageCredit | null;
};

/** An image offered by a provider; nothing is assigned until the user chooses one. */
export type ImageCandidate = {
    provider: 'commons' | 'off';
    reference: string;
    title: string;
    thumbnail_url: string;
    author: string | null;
    license: string | null;
    license_url: string | null;
    source_url: string;
};

export type InventoryItem = IngredientVisual & {
    id: number;
    ingredient: string;
    ingredient_id: number;
    location: StorageLocation;
    location_label: string;
    quantity: string;
    unit: string;
    unit_label: string;
    unit_group: string;
    label: string | null;
    can_step: boolean;
    can_decrease: boolean;
    /** RN-47: every location of this food, set to zero by «Se acabó». */
    food_locations?: string[];
    /** RN-44: the estimate when it differs from what is registered, and why. */
    estimate: {
        quantity: string;
        display: string;
        registered: string;
        can_decrease: boolean;
        consumed: { dish: string; when: string; quantity: string }[];
    } | null;
};

export type IngredientAlias = {
    id: number;
    alias: string;
};

export type IngredientLine = {
    ingredient: string;
    quantity: string;
    unit: string;
    note?: string | null;
    /** RN-42: «o bien» of the line before it (recipes). */
    alternative?: boolean;
};

/** RN-43: a preparation included in a recipe. */
export type RecipeComponentLine = {
    recipe_id: number;
    servings: number;
};

export type ComponentOption = {
    id: number;
    name: string;
    base_servings: number;
    archived: boolean;
};

/** RN-42: a group of alternative ingredients of a planned dish. */
export type DishAlternative = {
    recipe_id: number;
    recipe: string;
    group: number;
    chosen: number;
    confirmed: boolean;
    /** The option chosen before, when it is no longer in the recipe (proposed again). */
    stale?: string | null;
    options: {
        ingredient_id: number;
        name: string;
        quantity: string;
        in_stock: boolean;
    }[];
};

export type RecipeSummary = {
    id: number;
    name: string;
    base_servings: number;
    ingredient_count: number;
    archived: boolean;
};

export type RecipeOption = {
    id: number;
    name: string;
    base_servings: number;
    /** False when the recipe has no ingredients yet (the dish would be incomplete, RN-18). */
    complete: boolean;
};

export type PlannedDish = {
    id: number;
    /** Order within its lunch or dinner (RN-16). */
    position: number;
    type: 'recipe' | 'adhoc';
    name: string;
    recipe_id: number | null;
    recipe_archived: boolean;
    servings: number;
    /** RN-37: still shown, its ingredients no longer count. */
    not_eaten: boolean;
    ingredients: (IngredientLine & { display: string })[];
    complete: boolean;
    /** RN-42: groups of alternative ingredients and the option in use. */
    alternatives?: DishAlternative[];
};

export type MenuDay = {
    day: number;
    date: string;
    label: string;
    /** Already past in Europe/Madrid: kept as history, never generates purchases (RN-36). */
    is_past?: boolean;
};

export type RequirementLine = {
    ingredient: string;
    need: string;
    have: string | null;
    /** RN-44: registered stock when it differs from the estimate shown in `have`. */
    registered?: string | null;
    missing: string | null;
    status: 'enough' | 'short' | 'missing' | 'review';
    dishes: string[];
};

export type IncompleteDish = {
    slot: string;
    dish: string;
    reason: string;
};

export type ReviewReason =
    | 'incompatible_inventory'
    | 'calculation_changed'
    | 'no_longer_needed'
    | 'quantity_increased'
    | 'manual_with_calculation';

export type ShoppingItem = {
    id: number;
    name: string;
    is_generated: boolean;
    has_ingredient: boolean;
    /** Category of the food (null for free text or an unclassified food), to group the list. */
    category?: string | null;
    category_label?: string | null;
    quantity: string | null;
    unit: string | null;
    unit_label: string | null;
    unit_group: string | null;
    display: string | null;
    calculated: string | null;
    is_user_modified: boolean;
    is_purchased: boolean;
    needs_review: boolean;
    review_reason: ReviewReason | null;
    received: string | null;
    remaining: string | null;
    is_received: boolean;
    other_units: boolean;
    /** RN-46: what «Guardar lo marcado» proposes for this line (foods of the catalog only). */
    suggested?: { quantity: string; unit: string; location: string } | null;
    /** RN-36: stock, committed and available of this ingredient (period lists only). */
    detail: {
        need: string;
        have: string | null;
        committed: string;
        available: string | null;
        /** RN-47: what the recipes need, and what must stay at home of a staple food. */
        recipes?: string | null;
        restock?: string | null;
        restock_mode?: 'minimum' | 'once' | null;
        /** Pending dishes before the period that use the same stock (shown in «Ver cálculo»). */
        before?: string | null;
        /** RN-44: registered stock, when it differs from the estimate shown in `have`. */
        registered?: string | null;
        /** RN-41: what one pack holds and what is really missing, when bought in packs. */
        pack?: string | null;
        missing?: string | null;
    } | null;
};

export type ReceptionLine = {
    id: number;
    name: string;
    has_ingredient: boolean;
    quantity: string | null;
    unit: string | null;
    display: string | null;
    received: string | null;
    other_units: boolean;
    is_purchased: boolean;
    location: StorageLocation | null;
    stock: { location: StorageLocation; display: string }[];
};

export type ReceptionSummary = {
    id: number;
    confirmed_at: string;
    items: {
        id: number;
        name: string;
        received: boolean;
        unforeseen: boolean;
        display: string | null;
        location: string | null;
    }[];
};

export type SimilarIngredient = {
    id: number;
    name: string;
    via: string | null;
};

export type Ingredient = IngredientVisual & {
    id: number;
    name: string;
    default_unit: string | null;
    stock_count: number;
    recipe_count: number;
    in_menus: boolean;
    aliases: IngredientAlias[];
    /** RN-40, RN-41: its own conversions and the unit it is bought in. */
    purchase_unit?: string | null;
    pack?: string | null;
    /** RN-47: «Mínimo en casa» (minimum) or the single restock of «Se acabó» (once). */
    restock?: {
        mode: 'minimum' | 'once';
        quantity: string | null;
        unit: string | null;
        /** What is asked for now; null when a single restock already ended. */
        display: string | null;
    } | null;
    conversions?: { id: number; label: string }[];
};
