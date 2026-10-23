<?php

namespace App\Http\Requests\Ingredients;

use App\Actions\Ingredients\FoodUnitsEditor;
use App\Enums\Unit;
use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The unit a food is bought in (RN-41), or none.
 */
class UpdatePurchaseUnitRequest extends FormRequest
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
            'purchase_unit' => ['nullable', Rule::enum(Unit::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $unit = $this->purchaseUnit();

                if ($validator->errors()->isNotEmpty() || $unit === null) {
                    return;
                }

                $problem = app(FoodUnitsEditor::class)->purchaseProblem($this->ingredient(), $unit);

                if ($problem !== null) {
                    $validator->errors()->add('purchase_unit', $problem);
                }
            },
        ];
    }

    public function purchaseUnit(): ?Unit
    {
        return $this->filled('purchase_unit') ? Unit::from($this->string('purchase_unit')->toString()) : null;
    }

    public function ingredient(): Ingredient
    {
        /** @var Ingredient $ingredient */
        $ingredient = $this->route('ingredient');

        return $ingredient;
    }
}
