<?php

namespace App\Http\Requests\Concerns;

use App\Actions\Ingredients\IngredientCatalog;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\Ingredient;
use App\Support\IngredientName;
use App\Support\Quantity;
use Illuminate\Contracts\Validation\Validator as ContractValidator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;

/**
 * Ingredient lines of recipes and one-off dishes: catalog ingredients resolved by name or alias
 * (RN-03/RN-04), or new ones the user explicitly asked to create in the same form; positive
 * quantities (RN-07) and no repeated ingredient with a compatible unit.
 *
 * A similar ingredient is only ever a suggestion: it is sent to the form (flash
 * `ingredientSuggestions`, keyed by row) so the user can pick it, fix the name or create a new one.
 */
trait ValidatesIngredientRows
{
    /**
     * @var list<array{ingredient: Ingredient|string, quantity: Quantity, note: string|null, alternative: bool}>
     */
    private array $resolvedRows = [];

    /**
     * @var array<int, list<string>>
     */
    private array $similarByRow = [];

    /**
     * Drop fully empty rows and accept the Spanish decimal comma.
     */
    protected function prepareIngredientRows(): void
    {
        $rows = $this->input('ingredients');

        if (! is_array($rows)) {
            return;
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ingredient = trim((string) ($row['ingredient'] ?? ''));
            $quantity = str_replace(',', '.', trim((string) ($row['quantity'] ?? '')));

            if ($ingredient === '' && $quantity === '') {
                continue;
            }

            $clean[] = [...$row, 'ingredient' => $ingredient, 'quantity' => $quantity];
        }

        $this->merge(['ingredients' => $clean]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ingredientRowRules(): array
    {
        return [
            'ingredients' => ['array', 'max:40'],
            'ingredients.*.ingredient' => ['required', 'string', 'max:100'],
            'ingredients.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'ingredients.*.unit' => ['required', Rule::enum(Unit::class)],
            'ingredients.*.note' => ['nullable', 'string', 'max:150'],
            'ingredients.*.create' => ['boolean'],
            // RN-42: «or» of the row before it (recipes only).
            'ingredients.*.alternative' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function ingredientRowAttributes(): array
    {
        return [
            'ingredients.*.ingredient' => 'ingrediente',
            'ingredients.*.quantity' => 'cantidad',
            'ingredients.*.unit' => 'unidad',
            'ingredients.*.note' => 'nota',
        ];
    }

    protected function resolveIngredientRows(Validator $validator, Household $household): void
    {
        $catalog = app(IngredientCatalog::class);
        $seen = [];
        $this->resolvedRows = [];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $this->input('ingredients', []);

        foreach ($rows as $index => $row) {
            if ($validator->errors()->has("ingredients.{$index}.*")) {
                continue;
            }

            $typed = (string) $row['ingredient'];
            $name = IngredientName::clean($typed);
            $ingredient = $catalog->resolve($household, $typed);

            if ($ingredient === null && ! filter_var($row['create'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $similar = array_column($catalog->similar($household, $typed), 'name');
                $this->similarByRow[$index] = $similar;
                $hint = $similar === []
                    ? ' Corrige el nombre o créalo como ingrediente nuevo.'
                    : ' ¿Querías decir «'.implode('» o «', $similar).'»? Elige la sugerencia, corrige el nombre o créalo como ingrediente nuevo.';
                $validator->errors()->add("ingredients.{$index}.ingredient", "«{$name}» no está en tu catálogo.{$hint}");

                continue;
            }

            $quantity = Quantity::of((string) $row['quantity'], Unit::from((string) $row['unit']));
            // A new ingredient is created when saving, inside the same transaction (RN-03).
            $key = ($ingredient !== null ? $ingredient->id : 'new:'.IngredientName::normalize($typed)).':'.$quantity->unit->group()->value;
            $label = $ingredient !== null ? $ingredient->name : $name;

            if (isset($seen[$key])) {
                $validator->errors()->add("ingredients.{$index}.ingredient", "«{$label}» ya está en la lista con una unidad compatible. Junta las cantidades en una sola línea.");

                continue;
            }

            $seen[$key] = true;
            $note = trim((string) ($row['note'] ?? ''));
            $this->resolvedRows[] = [
                'ingredient' => $ingredient ?? $name,
                'quantity' => $quantity,
                'note' => $note === '' ? null : $note,
                'alternative' => filter_var($row['alternative'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
    }

    /**
     * Keep the suggestions next to the rows they belong to, so the form can offer them as buttons.
     */
    protected function failedValidation(ContractValidator $validator): void
    {
        if ($this->similarByRow !== []) {
            Inertia::flash('ingredientSuggestions', $this->similarByRow);
        }

        parent::failedValidation($validator);
    }

    /**
     * A string ingredient is a new one the user explicitly asked to create.
     *
     * @return list<array{ingredient: Ingredient|string, quantity: Quantity, note: string|null, alternative: bool}>
     */
    public function ingredientRows(): array
    {
        return $this->resolvedRows;
    }
}
