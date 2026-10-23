<?php

namespace App\Http\Requests\Ingredients;

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Mínimo en casa» of a food (RN-47): a quantity and a unit, or none to remove it.
 */
class UpdateMinimumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->ingredient()) === true;
    }

    protected function prepareForValidation(): void
    {
        $quantity = str_replace(',', '.', trim((string) $this->input('quantity', '')));
        $this->merge(['quantity' => $quantity === '' ? null : $quantity]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'required_with:quantity', Rule::enum(Unit::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['quantity' => 'cantidad mínima', 'unit' => 'unidad'];
    }

    public function minimum(): ?Quantity
    {
        $quantity = $this->validated('quantity');

        return is_numeric($quantity) ? Quantity::of((string) $quantity, Unit::from($this->string('unit')->toString())) : null;
    }

    public function ingredient(): Ingredient
    {
        /** @var Ingredient $ingredient */
        $ingredient = $this->route('ingredient');

        return $ingredient;
    }
}
