<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\Images\ImageProviderUnavailable;
use App\Actions\Ingredients\Images\IngredientImages;
use App\Actions\Ingredients\IngredientCatalog;
use App\Http\Requests\Ingredients\IngredientRequest;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use InvalidArgumentException;

class IngredientController extends Controller
{
    public function __construct(private readonly IngredientCatalog $catalog) {}

    /**
     * The catalog is managed from Inventory (decision 2026-10-21): old links keep working.
     */
    public function index(): RedirectResponse
    {
        return to_route('inventory.index');
    }

    public function store(IngredientRequest $request, IngredientImages $images): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $ingredient = $this->catalog->create($user->currentHousehold(), $request->string('name')->toString(), $request->defaultUnit(), $request->category());

        // The image chosen in the form (optional). If it cannot be kept, the ingredient stays.
        $image = $request->chosenImage();

        if ($image !== null) {
            try {
                $images->attach($ingredient, $image['provider'], $image['reference']);
            } catch (ImageProviderUnavailable|InvalidArgumentException) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => "Alimento «{$ingredient->name}» creado, pero no se ha podido guardar la imagen. Puedes elegirla de nuevo al editarlo."]);

                return back();
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Alimento «{$ingredient->name}» creado."]);

        return back();
    }

    public function update(IngredientRequest $request, Ingredient $ingredient): RedirectResponse
    {
        $this->catalog->update($ingredient, $request->string('name')->toString(), $request->defaultUnit(), $request->category());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alimento actualizado.']);

        return back();
    }

    public function destroy(Ingredient $ingredient): RedirectResponse
    {
        Gate::authorize('delete', $ingredient);

        $usage = $this->catalog->usage($ingredient);

        if ($usage !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "No se puede eliminar «{$ingredient->name}» porque se usa en {$usage}."]);

            return back();
        }

        $this->catalog->delete($ingredient);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Alimento «{$ingredient->name}» eliminado del catálogo."]);

        return back();
    }
}
