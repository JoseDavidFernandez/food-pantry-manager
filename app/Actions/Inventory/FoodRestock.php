<?php

namespace App\Actions\Inventory;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\ReceptionItem;
use App\Models\StockEvent;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staple foods (RN-47), with no daily task:
 * - «Mínimo en casa»: keep at least a quantity of a food. The period lists ask for it when the
 *   estimated stock left after the planned meals would be below it (on the same line as the
 *   recipes that need the food, never a second one).
 * - «Se acabó»: the food ran out. Every location is counted to zero in one operation (one
 *   `count` per row, like deleting a location). Without a minimum, it is asked for once: until
 *   a purchase of the food is added to the stock.
 */
class FoodRestock
{
    public const MINIMUM = 'minimum';

    public const ONCE = 'once';

    public function __construct(private readonly StockManager $stock) {}

    public function setMinimum(Ingredient $ingredient, ?Quantity $minimum): void
    {
        $ingredient->update($minimum === null
            ? ['restock_mode' => null, 'restock_quantity' => null, 'restock_unit' => null, 'restock_since' => null]
            : ['restock_mode' => self::MINIMUM, 'restock_quantity' => $minimum->amount, 'restock_unit' => $minimum->unit, 'restock_since' => null]);
    }

    /**
     * @return array{locations: int, once: Quantity|null} rows set to zero, and the single restock asked for
     */
    public function depleted(Ingredient $ingredient): array
    {
        return DB::transaction(function () use ($ingredient) {
            Ingredient::query()->whereKey($ingredient->id)->lockForUpdate()->first();
            $ingredient->refresh();

            $rows = $ingredient->inventoryItems()->lockForUpdate()->get();
            // What it is usually bought in, read before the rows go.
            $quantity = $this->usualPurchase($ingredient, $rows->first()?->toQuantity());

            foreach ($rows as $row) {
                $this->stock->remove($row);
            }

            $once = null;

            if ($ingredient->restock_mode !== self::MINIMUM && $quantity !== null) {
                $ingredient->update([
                    'restock_mode' => self::ONCE,
                    'restock_quantity' => $quantity->amount,
                    'restock_unit' => $quantity->unit,
                    'restock_since' => CarbonImmutable::now(),
                ]);
                $once = $quantity;
            }

            return ['locations' => $rows->count(), 'once' => $once];
        });
    }

    /**
     * The quantity to restock of a food while its restock is in force, or null.
     */
    public function active(Ingredient $ingredient): ?Quantity
    {
        if ($ingredient->restock_mode === null || $ingredient->restock_quantity === null || $ingredient->restock_unit === null) {
            return null;
        }

        if ($ingredient->restock_mode === self::ONCE && $this->purchasedSince($ingredient)) {
            return null;
        }

        return Quantity::of($ingredient->restock_quantity, $ingredient->restock_unit);
    }

    /**
     * Foods of a household whose restock is in force, with the quantity.
     *
     * @return list<array{0: Ingredient, 1: Quantity, 2: string}>
     */
    public function forHousehold(Household $household): array
    {
        $active = [];

        foreach ($household->ingredients()->whereNotNull('restock_mode')->get() as $ingredient) {
            $quantity = $this->active($ingredient);

            if ($quantity !== null) {
                $active[] = [$ingredient, $quantity, (string) $ingredient->restock_mode];
            }
        }

        return $active;
    }

    /**
     * The purchase that ends the single restock: an addition after «Se acabó».
     */
    private function purchasedSince(Ingredient $ingredient): bool
    {
        return $ingredient->restock_since !== null && StockEvent::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('kind', StockEvent::ADD)
            ->where('occurred_at', '>', $ingredient->restock_since->setTimezone((string) config('app.timezone')))
            ->exists();
    }

    /**
     * The last purchase received, else one pack of its purchase unit, else what was there,
     * else one of its usual unit.
     */
    private function usualPurchase(Ingredient $ingredient, ?Quantity $registered): ?Quantity
    {
        $last = ReceptionItem::query()->where('ingredient_id', $ingredient->id)->where('outcome', ReceptionItem::RECEIVED)->latest('id')->first();

        return $last?->received()
            ?? ($ingredient->purchase_unit !== null ? Quantity::of('1', $ingredient->purchase_unit) : null)
            ?? $registered
            ?? ($ingredient->default_unit !== null ? Quantity::of('1', $ingredient->default_unit) : null);
    }
}
