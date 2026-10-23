/**
 * Drag and drop in the weekly menu (USER-FLOWS §4). It complements the dialogs, which stay
 * as the keyboard and touch alternative. The payload travels in the DataTransfer with these
 * types, so only recipes of the recipe book and dishes of the calendar can be dropped.
 */
export const RECIPE_MIME = 'application/x-fpm-recipe';
export const DISH_MIME = 'application/x-fpm-dish';

/** A lunch or dinner of the week, as a target of «Mover a…» or «Añadir al menú». */
export type SlotOption = {
    key: string;
    day: number;
    meal: string;
    label: string;
    /** Names of the dishes it already holds, or null when empty. */
    dish: string | null;
    /** On a day already past: kept as history, never generates purchases (RN-36). */
    past?: boolean;
};
