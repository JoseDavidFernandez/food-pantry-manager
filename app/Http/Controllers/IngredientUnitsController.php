<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\FoodUnitsEditor;
use App\Actions\Ingredients\FoodUnitsRefused;
use App\Http\Requests\Ingredients\StoreIngredientConversionRequest;
use App\Http\Requests\Ingredients\UpdatePurchaseUnitRequest;
use App\Models\Ingredient;
use App\Models\IngredientConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The units of a food (RN-40, RN-41): its own conversions and its purchase unit.
 */
class IngredientUnitsController extends Controller
{
    public function __construct(private readonly FoodUnitsEditor $editor) {}

    public function store(StoreIngredientConversionRequest $request, Ingredient $ingredient): RedirectResponse
    {
        try {
            $conversion = $this->editor->addConversion($ingredient, $request->unit(), $request->factor(), $request->toUnit());
        } catch (FoodUnitsRefused $refused) {
            throw ValidationException::withMessages(['to_unit' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Equivalencia añadida a «'.$ingredient->name.'»: '.self::describe($conversion).'.']);

        return back();
    }

    public function destroy(Ingredient $ingredient, IngredientConversion $conversion): RedirectResponse
    {
        Gate::authorize('update', $ingredient);

        $cleared = $this->editor->removeConversion($ingredient, $conversion);

        Inertia::flash('toast', ['type' => $cleared ? 'warning' : 'success', 'message' => 'Equivalencia quitada: '.self::describe($conversion).'.'
            .($cleared ? ' El formato de compra ya no se podía calcular y se ha quitado.' : '')]);

        return back();
    }

    public function purchase(UpdatePurchaseUnitRequest $request, Ingredient $ingredient): RedirectResponse
    {
        $unit = $request->purchaseUnit();
        $this->editor->setPurchaseUnit($ingredient, $unit);

        Inertia::flash('toast', ['type' => 'success', 'message' => $unit === null
            ? "«{$ingredient->name}» ya no tiene formato de compra: se compra la cantidad que falta."
            : "«{$ingredient->name}» se comprará en {$unit->label()} enteras."]);

        return back();
    }

    public static function describe(IngredientConversion $conversion): string
    {
        $factor = str_replace('.', ',', rtrim(rtrim($conversion->factor, '0'), '.'));

        return '1 '.$conversion->unit->label('1').' = '.$factor.' '.$conversion->to_unit->label($conversion->factor);
    }
}
