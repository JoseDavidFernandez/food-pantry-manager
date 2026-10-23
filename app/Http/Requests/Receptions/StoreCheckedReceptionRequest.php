<?php

namespace App\Http\Requests\Receptions;

use App\Actions\ShoppingLists\ShoppingListLocator;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\ReceptionItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * «Guardar lo marcado» (RN-46): the lines in the cart, received in one operation with their
 * quantity and location. Lines not sent stay pending; nothing has to be decided about them.
 */
class StoreCheckedReceptionRequest extends FormRequest
{
    private ?ShoppingList $list = null;

    public function authorize(): bool
    {
        // Lists of other households are a 404 before validating.
        $this->shoppingList();

        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = [];

        foreach ((array) $this->input('lines', []) as $line) {
            if (is_array($line)) {
                $lines[] = [...$line, 'quantity' => str_replace(',', '.', trim(is_scalar($line['quantity'] ?? null) ? (string) $line['quantity'] : ''))];
            }
        }

        $this->merge(['lines' => $lines]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'lines.*.unit' => ['required', Rule::enum(Unit::class)],
            'lines.*.location' => ['required', Rule::enum(StorageLocation::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Marca en la lista lo que has comprado.',
            'lines.min' => 'Marca en la lista lo que has comprado.',
            'token.*' => 'El formulario ha caducado. Vuelve a cargar la lista.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['lines.*.quantity' => 'cantidad', 'lines.*.unit' => 'unidad', 'lines.*.location' => 'ubicación'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $items = $this->shoppingList()->items()->get()->keyBy('id');

                /** @var array<int, array<string, mixed>> $lines */
                $lines = (array) $this->input('lines', []);

                foreach ($lines as $index => $line) {
                    $item = $items->get((int) ($line['item_id'] ?? 0));

                    // Free text has no food in the catalog: it stays marked, outside the stock.
                    if ($item instanceof ShoppingListItem && $item->ingredient_id === null) {
                        $validator->errors()->add("lines.{$index}.item_id", "«{$item->name}» no es un alimento del catálogo: no se guarda en el inventario.");
                    }
                }
            },
        ];
    }

    /**
     * @return array<int, array{outcome: string, ingredient: null, quantity: Quantity, location: StorageLocation}>
     */
    public function decisions(): array
    {
        $decisions = [];

        /** @var list<array{item_id: int|string, quantity: string, unit: string, location: string}> $lines */
        $lines = (array) $this->validated('lines', []);

        foreach ($lines as $line) {
            $decisions[(int) $line['item_id']] = [
                'outcome' => ReceptionItem::RECEIVED,
                'ingredient' => null,
                'quantity' => Quantity::of($line['quantity'], Unit::from($line['unit'])),
                'location' => StorageLocation::from($line['location']),
            ];
        }

        return $decisions;
    }

    public function shoppingList(): ShoppingList
    {
        if ($this->list !== null) {
            return $this->list;
        }

        /** @var User $user */
        $user = $this->user();

        return $this->list = app(ShoppingListLocator::class)->list($user->currentHousehold(), (int) $this->route('list'));
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }
}
