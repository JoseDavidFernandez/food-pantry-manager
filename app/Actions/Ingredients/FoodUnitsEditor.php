<?php

namespace App\Actions\Ingredients;

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\IngredientConversion;
use App\Support\FoodUnits;
use Illuminate\Support\Facades\DB;

/**
 * Edits the units of a food (RN-40, RN-41): its own conversions and the unit it is bought in.
 * The conversions of a food form a tree: a conversion between units that are already
 * convertible (directly or through others) is refused, so there are never two different answers.
 */
class FoodUnitsEditor
{
    public function units(Ingredient $ingredient): FoodUnits
    {
        return FoodUnits::from($ingredient->conversions()->get(), $ingredient->purchase_unit);
    }

    /**
     * Why the conversion cannot be added, or null.
     */
    public function conversionProblem(Ingredient $ingredient, Unit $unit, Unit $to): ?string
    {
        if ($unit->group() === $to->group()) {
            return $unit === $to
                ? 'Elige dos unidades distintas.'
                : 'Esas unidades ya se convierten siempre (g y kg, ml y l): no hace falta añadirlo.';
        }

        if ($this->units($ingredient)->connected($unit->group(), $to->group())) {
            return "«{$ingredient->name}» ya convierte {$unit->label()} en {$to->label()} con las equivalencias que tiene. Quita antes la que quieras sustituir.";
        }

        return null;
    }

    /**
     * @param  numeric-string  $factor
     */
    public function addConversion(Ingredient $ingredient, Unit $unit, string $factor, Unit $to): IngredientConversion
    {
        return DB::transaction(function () use ($ingredient, $unit, $factor, $to) {
            // Two simultaneous additions are checked one after the other (no cycle can slip in).
            Ingredient::query()->whereKey($ingredient->id)->lockForUpdate()->first();

            if ($this->conversionProblem($ingredient, $unit, $to) !== null) {
                throw new FoodUnitsRefused((string) $this->conversionProblem($ingredient, $unit, $to));
            }

            return $ingredient->conversions()->create(['unit' => $unit, 'factor' => $factor, 'to_unit' => $to]);
        });
    }

    /**
     * Removes a conversion. A purchase unit that can no longer be converted is cleared, and the
     * result says so.
     */
    public function removeConversion(Ingredient $ingredient, IngredientConversion $conversion): bool
    {
        return DB::transaction(function () use ($ingredient, $conversion) {
            $conversion->delete();
            $purchase = $ingredient->purchase_unit;

            if ($purchase !== null && $this->purchaseProblem($ingredient, $purchase) !== null) {
                $ingredient->update(['purchase_unit' => null]);

                return true;
            }

            return false;
        });
    }

    /**
     * Why the food cannot be bought in this unit, or null.
     */
    public function purchaseProblem(Ingredient $ingredient, Unit $unit): ?string
    {
        if (! $unit->isIndivisible()) {
            return 'El formato de compra es un envase o pieza que se compra entero (bolsa, caja, paquete, malla…). Para comprar a peso no hace falta indicarlo.';
        }

        $units = $this->units($ingredient);
        $usual = $ingredient->default_unit?->group();
        $convertible = collect(Unit::cases())->contains(fn (Unit $other) => $other->group() !== $unit->group() && $units->connected($unit->group(), $other->group()));

        if (! $convertible && $usual !== $unit->group()) {
            return 'Indica antes cuánto contiene: añade una equivalencia como «1 '.$unit->label('1').' = 500 g».';
        }

        return null;
    }

    public function setPurchaseUnit(Ingredient $ingredient, ?Unit $unit): void
    {
        $ingredient->update(['purchase_unit' => $unit]);
    }
}
