<?php

namespace App\Actions\Receptions;

use App\Actions\Ingredients\IngredientCatalog;
use App\Actions\Inventory\StockManager;
use App\Enums\StorageLocation;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Reception;
use App\Models\ReceptionItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms a reception in one operation (RN-29, RN-30, DATA-MODEL §6.4): records every decision
 * and adds only what was received to the stock, combining compatible rows (RN-06, RN-08).
 * A list may have several partial receptions; each one must decide every line still pending.
 */
class ReceptionRecorder
{
    public function __construct(
        private readonly ReceptionProgress $progress,
        private readonly StockManager $stock,
        private readonly IngredientCatalog $catalog,
    ) {}

    /**
     * A string ingredient is a new one the user explicitly asked to create.
     *
     * @param  array<int, array{outcome: string, ingredient: Ingredient|string|null, quantity: Quantity|null, location: StorageLocation|null}>  $decisions  keyed by list line id
     * @param  list<array{ingredient: Ingredient|string, quantity: Quantity, location: StorageLocation}>  $extras  unforeseen products
     *                                                                                                             In partial mode («Guardar lo marcado», RN-46) only the lines sent are received: the rest
     *                                                                                                             stay pending without a decision, and a line that is no longer pending (another member
     *                                                                                                             received it meanwhile) is skipped and reported instead of rejecting the whole operation.
     * @return array{0: Reception, 1: bool, 2: list<string>} the reception, whether it was created now and the lines skipped
     *
     * @throws ValidationException
     */
    public function confirm(Household $household, ShoppingList $list, string $token, array $decisions, array $extras, bool $partial = false): array
    {
        if ($list->household_id !== $household->id) {
            throw new InvalidArgumentException('The list belongs to another household.');
        }

        return DB::transaction(function () use ($household, $list, $token, $decisions, $extras, $partial) {
            // Serialises receptions of the same list, so pending lines cannot change underneath.
            ShoppingList::query()->whereKey($list->id)->lockForUpdate()->first();

            $existing = Reception::query()->where('token', $token)->first();

            if ($existing !== null) {
                if ($existing->household_id !== $household->id) {
                    throw new NotFoundHttpException;
                }

                // RN-30: the same confirmation is never applied twice.
                return [$existing, false, []];
            }

            $pending = array_filter($this->progress->forList($list), fn (array $line) => $line['pending']);
            $skipped = [];

            if ($partial) {
                $items = $list->items()->whereKey(array_keys($decisions))->pluck('name', 'id');

                foreach (array_keys(array_diff_key($decisions, $pending)) as $id) {
                    $skipped[] = (string) ($items[$id] ?? '');
                    unset($decisions[$id]);
                }

                $pending = array_intersect_key($pending, $decisions);

                if ($pending === []) {
                    throw ValidationException::withMessages(['lines' => 'Lo marcado ya estaba guardado en el inventario: no se ha vuelto a sumar.']);
                }
            } else {
                $this->ensureEveryPendingLineIsDecided($pending, $decisions);
            }

            $receivedSomething = $extras !== [] || array_filter($decisions, fn (array $d) => $d['outcome'] === ReceptionItem::RECEIVED) !== [];

            if (! $receivedSomething) {
                throw ValidationException::withMessages(['lines' => 'Marca al menos una línea como recibida o añade un producto no previsto.']);
            }

            $reception = Reception::create([
                'household_id' => $household->id,
                'shopping_list_id' => $list->id,
                'token' => $token,
                'confirmed_at' => now(),
            ]);

            foreach ($pending as $id => $line) {
                $this->recordLine($household, $reception, $line['item'], $line['remaining'], $decisions[$id]);

                // Out of the cart: what is still missing is pending again, unmarked.
                if ($partial) {
                    $line['item']->forceFill(['is_purchased' => false])->save();
                }
            }

            foreach ($extras as $extra) {
                $ingredient = $this->ingredient($household, $extra['ingredient']);
                $this->receive($household, $reception, $ingredient, $extra['quantity'], $extra['location'], [
                    'is_unforeseen' => true,
                    'name' => $ingredient->name,
                ]);
            }

            return [$reception, true, $skipped];
        });
    }

    /**
     * RN-29: no line may be left undecided; a stale form (lines no longer pending) is rejected.
     *
     * @param  array<int, array{item: ShoppingListItem}>  $pending
     * @param  array<int, mixed>  $decisions
     *
     * @throws ValidationException
     */
    private function ensureEveryPendingLineIsDecided(array $pending, array $decisions): void
    {
        $undecided = array_diff_key($pending, $decisions);

        if ($undecided !== []) {
            $names = array_map(fn (array $line) => '«'.$line['item']->name.'»', $undecided);

            throw ValidationException::withMessages([
                'lines' => (count($undecided) === 1 ? 'Queda 1 línea sin decidir: ' : 'Quedan '.count($undecided).' líneas sin decidir: ').implode(', ', $names).'.',
            ]);
        }

        if (array_diff_key($decisions, $pending) !== []) {
            throw ValidationException::withMessages([
                'lines' => 'La lista ha cambiado desde que abriste el formulario. Vuelve a cargar la recepción.',
            ]);
        }
    }

    /**
     * @param  array{outcome: string, ingredient: Ingredient|string|null, quantity: Quantity|null, location: StorageLocation|null}  $decision
     */
    private function recordLine(Household $household, Reception $reception, ShoppingListItem $item, ?Quantity $remaining, array $decision): void
    {
        $attributes = [
            'shopping_list_item_id' => $item->id,
            'name' => $item->name,
            'suggested_quantity' => $remaining?->amount,
            'suggested_unit' => $remaining?->unit,
        ];

        if ($decision['outcome'] === ReceptionItem::NOT_PURCHASED) {
            // RN-30: lines not purchased never touch the stock and stay pending for a later reception.
            $reception->items()->create([...$attributes, 'ingredient_id' => $item->ingredient_id, 'outcome' => ReceptionItem::NOT_PURCHASED]);

            return;
        }

        if ($decision['quantity'] === null || $decision['location'] === null) {
            throw new InvalidArgumentException('A received line needs a quantity and a location.');
        }

        $ingredient = $item->ingredient ?? $this->ingredient($household, $decision['ingredient'] ?? $item->name);

        $this->receive($household, $reception, $ingredient, $decision['quantity'], $decision['location'], $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function receive(Household $household, Reception $reception, Ingredient $ingredient, Quantity $quantity, StorageLocation $location, array $attributes): void
    {
        $line = $reception->items()->create([
            ...$attributes,
            'ingredient_id' => $ingredient->id,
            'outcome' => ReceptionItem::RECEIVED,
            'quantity_received' => $quantity->amount,
            'unit' => $quantity->unit,
            'unit_group' => $quantity->unit->group(),
            'location' => $location,
        ]);

        // The stock event keeps the line it came from (RN-44): a reception is added once.
        $this->stock->add($household, $ingredient, $location, $quantity, source: $line);
    }

    /**
     * Resolve again inside the transaction, so the same new name typed twice creates one ingredient.
     */
    private function ingredient(Household $household, Ingredient|string $ingredient): Ingredient
    {
        if ($ingredient instanceof Ingredient) {
            return $ingredient;
        }

        return $this->catalog->findOrCreate($household, $ingredient);
    }
}
