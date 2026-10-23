<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\IngredientCatalog;
use App\Http\Requests\Ingredients\StoreIngredientAliasRequest;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IngredientAliasController extends Controller
{
    public function store(StoreIngredientAliasRequest $request, Ingredient $ingredient, IngredientCatalog $catalog): RedirectResponse
    {
        $alias = $catalog->addAlias($ingredient, $request->string('alias')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Alias «{$alias->alias}» añadido a «{$ingredient->name}»."]);

        return back();
    }

    public function destroy(Ingredient $ingredient, IngredientAlias $alias): RedirectResponse
    {
        Gate::authorize('update', $ingredient);

        $alias->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Alias «{$alias->alias}» eliminado."]);

        return back();
    }
}
