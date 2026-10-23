<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\IngredientCatalog;
use App\Actions\Receptions\ReceptionProgress;
use App\Actions\Receptions\ReceptionRecorder;
use App\Actions\ShoppingLists\ShoppingListLocator;
use App\Actions\ShoppingLists\ShoppingListSync;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Http\Requests\Receptions\StoreCheckedReceptionRequest;
use App\Http\Requests\Receptions\StoreReceptionRequest;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReceptionController extends Controller
{
    public function create(Request $request, ShoppingListLocator $locator, ShoppingListSync $sync, ReceptionProgress $progress, IngredientCatalog $catalog, int $list): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $household = $user->currentHousehold();
        $list = $locator->list($household, $list);

        // RN-29 with RN-36: purchases are received from the current list of a period.
        if (! $list->isActive()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Esta lista es del historial: registra la compra desde la lista actual.']);

            return to_route('shopping-lists.show', $list->id);
        }

        if (Gate::denies('household.write')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Tu rol en el hogar es de lectura: no puedes registrar compras.']);

            return to_route('shopping-list.period', ShoppingListController::periodQuery($list));
        }

        // The pending lines are those of the current needs, as when the list is opened.
        $list = $sync->refresh($household, $list);

        $pending = array_filter($progress->forList($list), fn (array $line) => $line['pending']);
        $ingredientIds = array_filter(array_map(fn (array $line) => $line['item']->ingredient_id, $pending));
        $stock = $household->inventoryItems()->whereIn('ingredient_id', $ingredientIds)->orderBy('location')->get()->groupBy('ingredient_id');

        $lines = collect($pending)
            ->sortBy(fn (array $line) => mb_strtolower($line['item']->name))
            ->map(function (array $line) use ($stock) {
                $item = $line['item'];
                $suggested = $line['remaining'];
                $rows = $stock->get($item->ingredient_id, collect())
                    ->filter(fn (InventoryItem $row) => $suggested === null || $row->unit_group === $suggested->unit->group());

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'has_ingredient' => $item->ingredient_id !== null,
                    'quantity' => $suggested?->formattedAmount(),
                    'unit' => $suggested?->unit->value,
                    'display' => $suggested?->readable(),
                    'received' => $this->joined($line['received_all']),
                    'other_units' => $line['other_units'],
                    'is_purchased' => $item->is_purchased,
                    // Suggest the location where the ingredient is already kept.
                    'location' => $rows->first()?->location->value,
                    'stock' => $rows->map(fn (InventoryItem $row) => [
                        'location' => $row->location->value,
                        'display' => $row->toQuantity()->readable(),
                    ])->values(),
                ];
            })
            ->values();

        return Inertia::render('receptions/Create', [
            'list' => ['id' => $list->id, 'label' => $list->label(), 'query' => ShoppingListController::periodQuery($list)],
            'token' => (string) Str::uuid(),
            'lines' => $lines,
            'units' => Unit::options(),
            'locations' => StorageLocation::options(),
            'suggestions' => $catalog->suggestions($household),
            'receptionCount' => $list->receptions()->count(),
        ]);
    }

    public function store(StoreReceptionRequest $request, ReceptionRecorder $recorder, int $list): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $shoppingList = $request->shoppingList();

        if (! $shoppingList->isActive()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Esta lista es del historial: registra la compra desde la lista actual.']);

            return to_route('shopping-lists.show', $shoppingList->id);
        }

        [$reception, $created] = $recorder->confirm(
            $user->currentHousehold(),
            $shoppingList,
            $request->token(),
            $request->decisions(),
            $request->extras(),
        );

        if (! $created) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Esta compra ya estaba registrada: no se ha vuelto a sumar al inventario.']);

            return to_route('shopping-list.period', ShoppingListController::periodQuery($shoppingList));
        }

        $count = $reception->items()->where('outcome', 'received')->count();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count === 1
                ? 'Compra registrada: 1 producto añadido al inventario.'
                : "Compra registrada: {$count} productos añadidos al inventario.",
        ]);

        return to_route('shopping-list.period', ShoppingListController::periodQuery($shoppingList));
    }

    /**
     * «Guardar lo marcado» (RN-46): the lines in the cart go to the stock in one operation, with
     * the quantities and locations confirmed in the sheet. The rest of the list stays pending.
     */
    public function checked(StoreCheckedReceptionRequest $request, ReceptionRecorder $recorder, int $list): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $shoppingList = $request->shoppingList();
        $back = to_route('shopping-list.period', ShoppingListController::periodQuery($shoppingList));

        if (! $shoppingList->isActive()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Esta lista es del historial: registra la compra desde la lista actual.']);

            return to_route('shopping-lists.show', $shoppingList->id);
        }

        [$reception, $created, $skipped] = $recorder->confirm($user->currentHousehold(), $shoppingList, $request->token(), $request->decisions(), [], partial: true);

        if (! $created) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Esta compra ya estaba guardada: no se ha vuelto a sumar al inventario.']);

            return $back;
        }

        $count = $reception->items()->where('outcome', 'received')->count();
        $message = $count === 1 ? 'Guardado en casa: 1 producto.' : "Guardado en casa: {$count} productos.";

        if ($skipped !== []) {
            $message .= ' Ya lo había guardado otra persona: «'.implode('», «', $skipped).'».';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return $back;
    }

    /**
     * @param  list<Quantity>  $quantities
     */
    private function joined(array $quantities): ?string
    {
        return $quantities === [] ? null : implode(' + ', array_map(fn (Quantity $q) => $q->readable(), $quantities));
    }
}
