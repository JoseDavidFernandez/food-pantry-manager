<?php

namespace App\Actions\ShoppingLists;

use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Support\FoodUnits;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Manual edits of a shopping list (RN-24, RN-25, RN-28). Nothing here touches the inventory.
 */
class ShoppingListEditor
{
    public const REVIEW_INCOMPATIBLE = 'incompatible_inventory';

    public const REVIEW_CHANGED = 'calculation_changed';

    public const REVIEW_NOT_NEEDED = 'no_longer_needed';

    public const REVIEW_INCREASED = 'quantity_increased';

    public const REVIEW_MANUAL_CALCULATED = 'manual_with_calculation';

    /**
     * Add an item. An ingredient whose compatible line already exists modifies that line
     * instead of creating another one (RN-24); free text creates a manual line.
     *
     * The list row is locked so two simultaneous adds are applied one after the other; if a
     * line still appears in between, the unique index wins and this add merges into it.
     *
     * @return array{0: ShoppingListItem, 1: bool} the line and whether an existing line was modified
     */
    public function add(ShoppingList $list, ?Ingredient $ingredient, string $name, ?Quantity $quantity): array
    {
        return DB::transaction(function () use ($list, $ingredient, $name, $quantity) {
            ShoppingList::query()->whereKey($list->id)->lockForUpdate()->first();

            if ($ingredient === null || $quantity === null) {
                $item = new ShoppingListItem([
                    'shopping_list_id' => $list->id,
                    'origin' => ShoppingListItem::MANUAL,
                    'name' => $name,
                ]);
                $item->setQuantity($quantity);
                $item->save();

                return [$item, false];
            }

            // The food's own conversions (RN-40): «1 bolsa» of potatoes joins the line in grams.
            $food = FoodUnitsMap::forHousehold($ingredient->household)->for($ingredient->id);
            $key = $ingredient->id.':'.$food->canonicalGroup($quantity->unit->group())->value;
            $existing = $list->items()->where('match_key', $key)->first();

            if ($existing !== null) {
                return [$this->merge($existing, $quantity, $food), true];
            }

            $item = new ShoppingListItem([
                'shopping_list_id' => $list->id,
                'origin' => ShoppingListItem::MANUAL,
                'ingredient_id' => $ingredient->id,
                'name' => $ingredient->name,
                'match_key' => $key,
            ]);
            $item->setQuantity($quantity);

            try {
                DB::transaction(fn () => $item->save());
            } catch (UniqueConstraintViolationException) {
                $existing = $list->items()->where('match_key', $key)->lockForUpdate()->firstOrFail();

                return [$this->merge($existing, $quantity, $food), true];
            }

            return [$item, false];
        });
    }

    private function merge(ShoppingListItem $existing, Quantity $quantity, FoodUnits $food): ShoppingListItem
    {
        $current = $existing->toQuantity();
        // Added in the unit of the line when the food converts it (2 bolsas + 500 g = 3 bolsas).
        $quantity = $current === null ? $quantity : ($food->convert($quantity, $current->unit->group()) ?? $quantity);

        $existing->setQuantity($existing->is_dismissed || $current === null ? $quantity : $current->plus($quantity));
        $existing->is_dismissed = false;
        $existing->is_user_modified = $existing->isGenerated() ? true : $existing->is_user_modified;
        $existing->needs_review = false;
        $existing->review_reason = null;
        $existing->save();

        return $existing;
    }

    /**
     * Editing a line clears its review flag; a generated line becomes "modified by the user".
     */
    public function update(ShoppingListItem $item, ?Quantity $quantity): void
    {
        $item->setQuantity($quantity);
        $item->is_user_modified = $item->isGenerated() ? true : $item->is_user_modified;
        $item->needs_review = false;
        $item->review_reason = null;
        $item->save();
    }

    /**
     * Manual lines are deleted; generated lines are dismissed (DATA-MODEL §2).
     */
    public function remove(ShoppingListItem $item): void
    {
        if (! $item->isGenerated()) {
            $item->delete();

            return;
        }

        $item->is_dismissed = true;
        $item->needs_review = false;
        $item->review_reason = null;
        $item->save();
    }

    /**
     * RN-25: purchased or not, the inventory is never modified.
     */
    public function togglePurchased(ShoppingListItem $item): void
    {
        $item->is_purchased = ! $item->is_purchased;
        $item->save();
    }

    public function markReviewed(ShoppingListItem $item): void
    {
        $item->needs_review = false;
        $item->review_reason = null;
        $item->save();
    }
}
