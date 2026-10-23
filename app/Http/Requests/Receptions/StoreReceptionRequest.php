<?php

namespace App\Http\Requests\Receptions;

use App\Actions\Ingredients\IngredientCatalog;
use App\Actions\ShoppingLists\ShoppingListLocator;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\ReceptionItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\IngredientName;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Confirm a reception (RN-29, USER-FLOWS §8): an explicit decision for each pending line and
 * optional unforeseen products. Unknown ingredients are only created when the user asks for it.
 * Whether every pending line was decided is checked when confirming, under a lock.
 */
class StoreReceptionRequest extends FormRequest
{
    private ?ShoppingList $list = null;

    /**
     * @var array<int, Ingredient|string>
     */
    private array $lineIngredients = [];

    /**
     * @var array<int, Ingredient|string>
     */
    private array $extraIngredients = [];

    public function authorize(): bool
    {
        // Lists of other households (or weeks without list) are a 404 before validating.
        $this->shoppingList();

        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = [];

        foreach ((array) $this->input('lines', []) as $line) {
            if (is_array($line)) {
                $lines[] = [...$line, 'quantity' => $this->decimal($line['quantity'] ?? null)];
            }
        }

        $extras = [];

        foreach ((array) $this->input('extras', []) as $extra) {
            if (! is_array($extra)) {
                continue;
            }

            $ingredient = trim((string) ($extra['ingredient'] ?? ''));
            $quantity = $this->decimal($extra['quantity'] ?? null);

            // Rows left completely empty are ignored.
            if ($ingredient === '' && $quantity === '') {
                continue;
            }

            $extras[] = [...$extra, 'ingredient' => $ingredient, 'quantity' => $quantity];
        }

        $this->merge(['lines' => $lines, 'extras' => $extras]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $received = 'required_if:lines.*.outcome,'.ReceptionItem::RECEIVED;
        $quantity = ['numeric', 'decimal:0,3', 'gt:0', 'max:999999999'];

        return [
            'token' => ['required', 'uuid'],
            'lines' => ['array', 'max:200'],
            'lines.*.item_id' => ['required', 'integer', 'distinct'],
            'lines.*.outcome' => ['required', Rule::in([ReceptionItem::RECEIVED, ReceptionItem::NOT_PURCHASED])],
            'lines.*.quantity' => ['nullable', $received, ...$quantity],
            'lines.*.unit' => ['nullable', $received, Rule::enum(Unit::class)],
            'lines.*.location' => ['nullable', $received, Rule::enum(StorageLocation::class)],
            'lines.*.ingredient' => ['nullable', 'string', 'max:100'],
            'lines.*.create_ingredient' => ['boolean'],
            'lines.*.confirm_new' => ['boolean'],
            'extras' => ['array', 'max:30'],
            'extras.*.ingredient' => ['required', 'string', 'max:100'],
            'extras.*.quantity' => ['required', ...$quantity],
            'extras.*.unit' => ['required', Rule::enum(Unit::class)],
            'extras.*.location' => ['required', Rule::enum(StorageLocation::class)],
            'extras.*.create_ingredient' => ['boolean'],
            'extras.*.confirm_new' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.*' => 'El formulario ha caducado. Vuelve a cargar la recepción.',
            'lines.*.outcome.required' => 'Indica si lo has recibido o no lo has comprado.',
            'lines.*.quantity.required_if' => 'Indica la cantidad recibida.',
            'lines.*.unit.required_if' => 'Indica la unidad.',
            'lines.*.location.required_if' => 'Indica dónde lo guardas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lines.*.quantity' => 'cantidad',
            'lines.*.unit' => 'unidad',
            'lines.*.location' => 'ubicación',
            'lines.*.ingredient' => 'ingrediente',
            'extras.*.ingredient' => 'ingrediente',
            'extras.*.quantity' => 'cantidad',
            'extras.*.unit' => 'unidad',
            'extras.*.location' => 'ubicación',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $household = $this->household();
                $items = $this->shoppingList()->items()->get()->keyBy('id');

                /** @var array<int, array<string, mixed>> $lines */
                $lines = (array) $this->input('lines', []);

                foreach ($lines as $index => $line) {
                    $item = $items->get((int) ($line['item_id'] ?? 0));

                    // Lines of other lists are treated as a stale form when confirming.
                    if (! $item instanceof ShoppingListItem || $item->ingredient_id !== null || ($line['outcome'] ?? null) !== ReceptionItem::RECEIVED) {
                        continue;
                    }

                    // A free-text line goes to the stock as a catalog ingredient, chosen or created explicitly.
                    $typed = trim((string) ($line['ingredient'] ?? '')) ?: $item->name;
                    $resolved = $this->resolveIngredient($validator, $household, "lines.{$index}", $typed, $line);

                    if ($resolved !== null) {
                        $this->lineIngredients[$index] = $resolved;
                    }
                }

                /** @var array<int, array<string, mixed>> $extras */
                $extras = (array) $this->input('extras', []);

                foreach ($extras as $index => $extra) {
                    if ($validator->errors()->has("extras.{$index}.ingredient")) {
                        continue;
                    }

                    $resolved = $this->resolveIngredient($validator, $household, "extras.{$index}", (string) $extra['ingredient'], $extra);

                    if ($resolved !== null) {
                        $this->extraIngredients[$index] = $resolved;
                    }
                }
            },
        ];
    }

    public function shoppingList(): ShoppingList
    {
        if ($this->list !== null) {
            return $this->list;
        }

        return $this->list = app(ShoppingListLocator::class)->list($this->household(), (int) $this->route('list'));
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }

    /**
     * @return array<int, array{outcome: string, ingredient: Ingredient|string|null, quantity: Quantity|null, location: StorageLocation|null}>
     */
    public function decisions(): array
    {
        $decisions = [];

        /** @var array<int, array<string, mixed>> $lines */
        $lines = (array) $this->validated('lines', []);

        foreach ($lines as $index => $line) {
            $received = $line['outcome'] === ReceptionItem::RECEIVED;

            $decisions[(int) $line['item_id']] = [
                'outcome' => (string) $line['outcome'],
                'ingredient' => $this->lineIngredients[$index] ?? null,
                'quantity' => $received ? Quantity::of((string) $line['quantity'], Unit::from((string) $line['unit'])) : null,
                'location' => $received ? StorageLocation::from((string) $line['location']) : null,
            ];
        }

        return $decisions;
    }

    /**
     * @return list<array{ingredient: Ingredient|string, quantity: Quantity, location: StorageLocation}>
     */
    public function extras(): array
    {
        $extras = [];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $this->validated('extras', []);

        foreach ($rows as $index => $row) {
            $extras[] = [
                'ingredient' => $this->extraIngredients[$index],
                'quantity' => Quantity::of((string) $row['quantity'], Unit::from((string) $row['unit'])),
                'location' => StorageLocation::from((string) $row['location']),
            ];
        }

        return $extras;
    }

    /**
     * Same explicit creation rules as adding stock: an unknown name needs «Crear el ingrediente»
     * and, if it looks like an existing one, also the confirmation that it is a new ingredient.
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveIngredient(Validator $validator, Household $household, string $prefix, string $typed, array $row): Ingredient|string|null
    {
        $catalog = app(IngredientCatalog::class);
        $name = IngredientName::clean($typed);

        if ($name === '') {
            $validator->errors()->add("{$prefix}.ingredient", 'Indica el ingrediente.');

            return null;
        }

        $ingredient = $catalog->resolve($household, $typed);

        if ($ingredient !== null) {
            return $ingredient;
        }

        $similar = array_column($catalog->similar($household, $typed), 'name');
        $suggestion = $similar === [] ? '' : ' ¿Querías decir «'.implode('» o «', $similar).'»?';

        if (! filter_var($row['create_ingredient'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $validator->errors()->add("{$prefix}.ingredient", "«{$name}» no está en tu catálogo.{$suggestion} Elige un ingrediente o marca «Crear el ingrediente».");

            return null;
        }

        if ($similar !== [] && ! filter_var($row['confirm_new'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $validator->errors()->add("{$prefix}.ingredient", "«{$name}» se parece a un ingrediente que ya tienes.{$suggestion} Escribe ese nombre o confirma que es un ingrediente nuevo.");

            return null;
        }

        return $name;
    }

    private function household(): Household
    {
        /** @var User $user */
        $user = $this->user();

        return $user->currentHousehold();
    }

    private function decimal(mixed $value): string
    {
        return str_replace(',', '.', trim(is_scalar($value) ? (string) $value : ''));
    }
}
