<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Concerns\NormalizesQuantity;
use App\Models\InventoryItem;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Quick adjustment of a stock row (USER-FLOWS §3): a new amount in the row's own unit, or one
 * unit up/down for indivisible units. Location, unit and label stay as they are.
 */
class AdjustInventoryItemRequest extends FormRequest
{
    use NormalizesQuantity;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->item()) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'step' => ['nullable', 'in:up,down', 'required_without:quantity', 'prohibits:quantity'],
            'quantity' => ['nullable', 'required_without:step', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.required_without' => 'Indica la cantidad que tienes ahora.',
            'quantity.gt' => 'La cantidad debe ser mayor que 0. Para quitarlo del todo, elimina la existencia.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->filled('step')) {
                    return;
                }

                if (! $this->item()->unit->isIndivisible()) {
                    $validator->errors()->add('quantity', 'En gramos o mililitros, escribe la cantidad nueva.');
                } elseif ($this->input('step') === 'down' && $this->stepped() === null) {
                    $validator->errors()->add('quantity', 'No puede quedar en 0. Para quitarlo del todo, elimina la existencia.');
                }
            },
        ];
    }

    public function item(): InventoryItem
    {
        /** @var InventoryItem $item */
        $item = $this->route('inventory');

        return $item;
    }

    /**
     * The new amount, always in the row's unit (RN-07: never zero or negative).
     */
    public function quantity(): Quantity
    {
        if ($this->filled('step')) {
            return $this->stepped() ?? $this->item()->toQuantity();
        }

        return Quantity::of($this->string('quantity')->toString(), $this->item()->unit);
    }

    private function stepped(): ?Quantity
    {
        $current = $this->item()->toQuantity();
        $one = Quantity::of('1', $current->unit);

        return $this->input('step') === 'up' ? $current->plus($one) : $current->minus($one);
    }
}
