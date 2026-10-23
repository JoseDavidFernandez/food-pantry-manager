<?php

namespace App\Http\Requests\Ingredients;

use App\Actions\Ingredients\FoodUnitsEditor;
use App\Enums\Unit;
use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A conversion of one food (RN-40): 1 `unit` = `factor` `to_unit`.
 */
class StoreIngredientConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->ingredient()) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit' => ['required', Rule::enum(Unit::class)],
            'factor' => ['required', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,6'],
            'to_unit' => ['required', Rule::enum(Unit::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['unit' => 'unidad', 'factor' => 'cantidad', 'to_unit' => 'unidad equivalente'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $problem = app(FoodUnitsEditor::class)->conversionProblem($this->ingredient(), $this->unit(), $this->toUnit());

                if ($problem !== null) {
                    $validator->errors()->add('to_unit', $problem);
                }
            },
        ];
    }

    public function unit(): Unit
    {
        return Unit::from($this->string('unit')->toString());
    }

    public function toUnit(): Unit
    {
        return Unit::from($this->string('to_unit')->toString());
    }

    /**
     * @return numeric-string
     */
    public function factor(): string
    {
        $factor = $this->string('factor')->toString();

        // Already validated as a positive number with at most six decimals.
        return is_numeric($factor) ? bcadd($factor, '0', 6) : '0';
    }

    public function ingredient(): Ingredient
    {
        /** @var Ingredient $ingredient */
        $ingredient = $this->route('ingredient');

        return $ingredient;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('factor'))) {
            $this->merge(['factor' => str_replace(',', '.', $this->input('factor'))]);
        }
    }
}
