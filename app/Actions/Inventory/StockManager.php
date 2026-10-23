<?php

namespace App\Actions\Inventory;

use App\Enums\StorageLocation;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ReceptionItem;
use App\Models\StockEvent;
use App\Support\IncompatibleUnitsException;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Direct stock edits by the user (RN-08, RN-09). Compatible quantities share one row per
 * ingredient and location and are combined following RN-06.
 *
 * Every change is also recorded as a stock event (RN-44), from which the estimated stock is
 * computed: an addition (`add`), a count of the row (`count`: the user states its real quantity
 * by editing, adjusting or deleting it) or a move to another location (`move`).
 */
class StockManager
{
    /**
     * Add stock: combine with the compatible row in that location, or create a new one.
     */
    public function add(Household $household, Ingredient $ingredient, StorageLocation $location, Quantity $quantity, ?string $label = null, ?ReceptionItem $source = null): InventoryItem
    {
        if ($ingredient->household_id !== $household->id) {
            throw new InvalidArgumentException('The ingredient belongs to another household.');
        }

        return DB::transaction(function () use ($household, $ingredient, $location, $quantity, $label, $source) {
            $existing = $this->compatibleRow($household, $ingredient, $location, $quantity)->lockForUpdate()->first();
            $this->record($household->id, $ingredient->id, StockEvent::ADD, $location, $quantity, quantity: $quantity, source: $source);

            if ($existing !== null) {
                $existing->setQuantity($existing->toQuantity()->plus($quantity));
                $existing->label ??= $label;
                $existing->save();

                return $existing;
            }

            $item = new InventoryItem([
                'household_id' => $household->id,
                'ingredient_id' => $ingredient->id,
                'location' => $location,
                'label' => $label,
            ]);
            $item->setQuantity($quantity);
            $item->save();

            return $item;
        });
    }

    /**
     * Edit a row. The unit may only change within its compatible group; moving to a location
     * that already has a compatible row merges both rows.
     *
     * @throws IncompatibleUnitsException
     */
    public function update(InventoryItem $item, Quantity $quantity, StorageLocation $location, ?string $label, bool $counted = false): InventoryItem
    {
        if ($quantity->unit->group() !== $item->unit_group) {
            throw new IncompatibleUnitsException($item->unit, $quantity->unit);
        }

        return DB::transaction(function () use ($item, $quantity, $location, $label, $counted) {
            // A quantity stated by the user is a count: a new one, or the same one when the
            // caller says it was stated on purpose ($counted). The same amount in g or kg is not.
            $changed = $counted || $item->toQuantity()->compareTo($quantity) !== 0;

            if ($location !== $item->location) {
                $this->record($item->household_id, $item->ingredient_id, StockEvent::MOVE, $item->location, $quantity, to: $location, quantity: $changed ? $quantity : null);
            } elseif ($changed) {
                $this->record($item->household_id, $item->ingredient_id, StockEvent::COUNT, $item->location, $quantity, quantity: $quantity);
            }

            if ($location !== $item->location) {
                $target = InventoryItem::query()
                    ->where('household_id', $item->household_id)
                    ->where('ingredient_id', $item->ingredient_id)
                    ->where('location', $location)
                    ->where('unit_group', $item->unit_group)
                    ->lockForUpdate()
                    ->first();

                if ($target !== null) {
                    $target->setQuantity($target->toQuantity()->plus($quantity));
                    $target->label ??= $label;
                    $target->save();
                    $item->delete();

                    return $target;
                }
            }

            $item->setQuantity($quantity);
            $item->location = $location;
            $item->label = $label;
            $item->save();

            return $item;
        });
    }

    /**
     * Delete a row: the user states that nothing is left there (a count to zero).
     */
    public function remove(InventoryItem $item): void
    {
        DB::transaction(function () use ($item) {
            $this->record($item->household_id, $item->ingredient_id, StockEvent::COUNT, $item->location, $item->toQuantity(), quantity: null);
            $item->delete();
        });
    }

    /**
     * @param  Quantity  $like  gives the compatible group of the row
     */
    private function record(int $householdId, int $ingredientId, string $kind, StorageLocation $location, Quantity $like, ?StorageLocation $to = null, ?Quantity $quantity = null, ?ReceptionItem $source = null): void
    {
        StockEvent::query()->create([
            'household_id' => $householdId,
            'ingredient_id' => $ingredientId,
            'kind' => $kind,
            'location' => $location,
            'unit_group' => $like->unit->group(),
            'to_location' => $to,
            // A count without quantity is a count to zero.
            'quantity' => $quantity !== null ? $quantity->amount : ($kind === StockEvent::COUNT ? '0' : null),
            'unit' => $quantity !== null ? $quantity->unit : $like->unit,
            'occurred_at' => CarbonImmutable::now(),
            'reception_item_id' => $source?->id,
        ]);
    }

    /**
     * @return Builder<InventoryItem>
     */
    private function compatibleRow(Household $household, Ingredient $ingredient, StorageLocation $location, Quantity $quantity): Builder
    {
        return InventoryItem::query()
            ->where('household_id', $household->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('location', $location)
            ->where('unit_group', $quantity->unit->group());
    }
}
