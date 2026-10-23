<?php

namespace App\Http\Requests\ShoppingLists;

use App\Actions\Ingredients\IngredientCatalog;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\IngredientName;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add a line to the shopping list (RN-24): a catalog ingredient (by name or alias) with
 * quantity and unit, or a free-text item whose quantity is optional.
 */
class StoreShoppingItemRequest extends FormRequest
{
    private ?Ingredient $resolved = null;

    protected function prepareForValidation(): void
    {
        $quantity = str_replace(',', '.', trim((string) $this->input('quantity', '')));

        $this->merge([
            'quantity' => $quantity === '' ? null : $quantity,
            'unit' => $this->filled('unit') ? $this->input('unit') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'required_with:quantity', Rule::enum(Unit::class)],
            'free_text' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item' => 'artículo',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('item')) {
                    return;
                }

                /** @var User $user */
                $user = $this->user();
                $catalog = app(IngredientCatalog::class);
                $household = $user->currentHousehold();
                $typed = $this->string('item')->toString();
                $this->resolved = $catalog->resolve($household, $typed);

                if ($this->resolved !== null) {
                    if (! $this->filled('quantity')) {
                        $validator->errors()->add('quantity', 'Indica la cantidad de «'.$this->resolved->name.'».');
                    }

                    return;
                }

                $similar = array_column($catalog->similar($household, $typed), 'name');

                if ($similar !== [] && ! $this->boolean('free_text')) {
                    $name = IngredientName::clean($typed);
                    $validator->errors()->add('item', "«{$name}» no está en tu catálogo. ¿Querías decir «".implode('» o «', $similar).'»? Escríbelo así o marca «Añadir como texto libre».');
                }
            },
        ];
    }

    public function ingredient(): ?Ingredient
    {
        return $this->resolved;
    }

    public function itemName(): string
    {
        return IngredientName::clean($this->string('item')->toString());
    }

    public function quantity(): ?Quantity
    {
        return $this->filled('quantity') && $this->filled('unit')
            ? Quantity::of($this->string('quantity')->toString(), Unit::from($this->string('unit')->toString()))
            : null;
    }
}
