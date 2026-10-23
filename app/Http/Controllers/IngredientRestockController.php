<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\FoodRestock;
use App\Http\Requests\Ingredients\UpdateMinimumRequest;
use App\Models\Ingredient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Staple foods (RN-47): «Mínimo en casa» and «Se acabó».
 */
class IngredientRestockController extends Controller
{
    public function __construct(private readonly FoodRestock $restock) {}

    public function minimum(UpdateMinimumRequest $request, Ingredient $ingredient): RedirectResponse
    {
        $minimum = $request->minimum();
        $this->restock->setMinimum($ingredient, $minimum);

        Inertia::flash('toast', ['type' => 'success', 'message' => $minimum === null
            ? "«{$ingredient->name}» ya no tiene mínimo en casa."
            : "«{$ingredient->name}»: se pedirá en la lista cuando vaya a quedar menos de {$minimum->format()}."]);

        return back();
    }

    public function depleted(Ingredient $ingredient): RedirectResponse
    {
        Gate::authorize('update', $ingredient);

        $result = $this->restock->depleted($ingredient);

        $message = "«{$ingredient->name}»: 0 en casa.";

        if ($ingredient->fresh()?->restock_mode === FoodRestock::MINIMUM) {
            $message .= ' Se pedirá en la lista para volver a tener el mínimo.';
        } elseif ($result['once'] !== null) {
            $message .= " Se ha añadido a la lista ({$result['once']->format()}) hasta que registres su compra.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
