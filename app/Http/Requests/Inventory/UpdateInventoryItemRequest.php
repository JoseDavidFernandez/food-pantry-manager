<?php

namespace App\Http\Requests\Inventory;

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Http\Requests\Concerns\NormalizesQuantity;
use App\Models\InventoryItem;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edit stock: quantity, unit within the same compatible group, location and label.
 */
class UpdateInventoryItemRequest extends FormRequest
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
            'location' => ['required', Rule::enum(StorageLocation::class)],
            'quantity' => $this->quantityRules(),
            'unit' => ['required', Rule::enum(Unit::class)],
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $unit = Unit::tryFrom($this->string('unit')->toString());

                if ($unit !== null && $unit->group() !== $this->item()->unit_group) {
                    $validator->errors()->add('unit', 'Solo puedes cambiar a una unidad compatible (g ↔ kg, ml ↔ l). Para otra unidad, añade una existencia nueva.');
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

    public function location(): StorageLocation
    {
        return StorageLocation::from($this->string('location')->toString());
    }

    public function quantity(): Quantity
    {
        return Quantity::of($this->string('quantity')->toString(), Unit::from($this->string('unit')->toString()));
    }

    public function label(): ?string
    {
        $label = trim($this->string('label')->toString());

        return $label === '' ? null : $label;
    }
}
