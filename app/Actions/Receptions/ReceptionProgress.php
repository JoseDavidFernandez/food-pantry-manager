<?php

namespace App\Actions\Receptions;

use App\Enums\UnitGroup;
use App\Models\ReceptionItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;

/**
 * What has been received so far for each visible line of a list, across its receptions since
 * the line was last calculated, and what is still pending. Only quantities of the line's compatible group reduce what is
 * pending, also those the food converts (RN-40: 500 g received for a line of 1 bolsa of 500 g); a
 * reception in another unit is shown but never subtracted (as in RN-22).
 */
class ReceptionProgress
{
    /**
     * @return array<int, array{
     *     item: ShoppingListItem,
     *     received: list<Quantity>,
     *     received_all: list<Quantity>,
     *     remaining: Quantity|null,
     *     pending: bool,
     *     other_units: bool
     * }>
     */
    public function forList(ShoppingList $list): array
    {
        $items = $list->items()->where('is_dismissed', false)->with('ingredient')->orderBy('id')->get();

        $received = ReceptionItem::query()
            ->whereIn('shopping_list_item_id', $items->modelKeys())
            ->where('outcome', ReceptionItem::RECEIVED)
            ->orderBy('id')
            ->get()
            ->groupBy('shopping_list_item_id');

        // A line recalculated when closing the menu already discounts the stock, and with it what
        // was received before (RN-27): only receptions after that recalculation count again.
        $counts = fn (ShoppingListItem $item, ReceptionItem $line) => $item->receptions_counted_after === null
            || $line->reception_id > $item->receptions_counted_after;

        $progress = [];
        $units = FoodUnitsMap::forHousehold($list->household);

        foreach ($items as $item) {
            $wanted = $item->toQuantity();
            $food = $units->for($item->ingredient_id);

            /** @var array<string, Quantity> $byGroup */
            $byGroup = [];
            /** @var array<string, Quantity> $allByGroup */
            $allByGroup = [];

            foreach ($received->get($item->id, collect()) as $line) {
                /** @var ReceptionItem $line */
                $quantity = $line->received();

                if ($quantity === null) {
                    continue;
                }

                $group = $quantity->unit->group()->value;
                // Everything that arrived for this line, only to show it («Recibido 250 g»).
                $allByGroup[$group] = isset($allByGroup[$group]) ? $allByGroup[$group]->plus($quantity) : $quantity;

                if ($counts($item, $line)) {
                    $counted = $wanted === null ? $quantity : ($food->convert($quantity, $wanted->unit->group()) ?? $quantity);
                    $countedGroup = $counted->unit->group()->value;
                    $byGroup[$countedGroup] = isset($byGroup[$countedGroup]) ? $byGroup[$countedGroup]->plus($counted) : $counted;
                }
            }

            if ($wanted === null) {
                // Free text without quantity: done once something was received.
                $remaining = null;
                $pending = $byGroup === [];
                $otherUnits = false;
            } else {
                $group = $wanted->unit->group()->value;
                $remaining = isset($byGroup[$group]) ? $wanted->minus($byGroup[$group]) : $wanted;
                $pending = $remaining !== null;
                // Shown as «parte en otra unidad»: anything that arrived in a non-convertible unit.
                $otherUnits = array_filter(
                    array_keys($allByGroup),
                    fn (string $other) => ! $food->connected(UnitGroup::from($other), $wanted->unit->group()),
                ) !== [];
            }

            $progress[$item->id] = [
                'item' => $item,
                'received' => array_values($byGroup),
                'received_all' => array_values($allByGroup),
                'remaining' => $remaining,
                'pending' => $pending,
                'other_units' => $otherUnits,
            ];
        }

        return $progress;
    }
}
