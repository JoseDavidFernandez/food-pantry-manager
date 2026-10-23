<?php

namespace App\Http\Requests\ShoppingLists;

use App\Actions\ShoppingLists\ShoppingListLocator;
use App\Enums\Unit;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edit the quantity of a line. Ingredient lines need a quantity in their compatible unit group;
 * free-text lines may have none. The line is resolved within the user's household (404 otherwise).
 */
class UpdateShoppingItemRequest extends FormRequest
{
    private ?ShoppingListItem $item = null;

    public function item(): ShoppingListItem
    {
        if ($this->item === null) {
            /** @var User $user */
            $user = $this->user();

            $this->item = app(ShoppingListLocator::class)->item($user->currentHousehold(), (int) $this->route('list'), (int) $this->route('item'));
        }

        return $this->item;
    }

    public function authorize(): bool
    {
        $this->item();

        return true;
    }

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
            'quantity' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'required_with:quantity', Rule::enum(Unit::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $item = $this->item();
                $group = $item->unit_group;

                if ($item->ingredient_id === null || $group === null) {
                    return;
                }

                if (! $this->filled('quantity')) {
                    $validator->errors()->add('quantity', 'Indica la cantidad. Para quitar la línea, elimínala.');

                    return;
                }

                $unit = Unit::tryFrom($this->string('unit')->toString());

                if ($unit !== null && $unit->group() !== $group) {
                    $validator->errors()->add('unit', 'Solo puedes usar una unidad compatible (g ↔ kg, ml ↔ l).');
                }
            },
        ];
    }

    public function quantity(): ?Quantity
    {
        return $this->filled('quantity') && $this->filled('unit')
            ? Quantity::of($this->string('quantity')->toString(), Unit::from($this->string('unit')->toString()))
            : null;
    }
}
