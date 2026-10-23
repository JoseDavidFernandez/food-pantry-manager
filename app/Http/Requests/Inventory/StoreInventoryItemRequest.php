<?php

namespace App\Http\Requests\Inventory;

use App\Actions\Ingredients\IngredientCatalog;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Http\Requests\Concerns\NormalizesQuantity;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\IngredientName;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add stock (USER-FLOWS §3). The ingredient is typed and resolved by name or alias;
 * an unknown ingredient is only created when the user explicitly asks for it.
 */
class StoreInventoryItemRequest extends FormRequest
{
    use NormalizesQuantity;

    private ?Ingredient $resolved = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ingredient' => ['required', 'string', 'max:100'],
            'create_ingredient' => ['boolean'],
            'confirm_new' => ['boolean'],
            'location' => ['required', Rule::enum(StorageLocation::class)],
            'quantity' => $this->quantityRules(),
            'unit' => ['required', Rule::enum(Unit::class)],
            'label' => ['nullable', 'string', 'max:100'],
            // Only used when the ingredient is created here.
            'category' => ['nullable', Rule::enum(IngredientCategory::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('ingredient') || IngredientName::clean($this->string('ingredient')->toString()) === '') {
                    return;
                }

                /** @var User $user */
                $user = $this->user();
                $catalog = app(IngredientCatalog::class);
                $household = $user->currentHousehold();
                $typed = $this->string('ingredient')->toString();
                $this->resolved = $catalog->resolve($household, $typed);

                if ($this->resolved !== null) {
                    return;
                }

                $name = IngredientName::clean($typed);
                $similar = array_column($catalog->similar($household, $typed), 'name');
                $suggestion = $similar === [] ? '' : ' ¿Querías decir «'.implode('» o «', $similar).'»?';

                if (! $this->boolean('create_ingredient')) {
                    $validator->errors()->add('ingredient', "«{$name}» no está en tu catálogo.{$suggestion} Elige una sugerencia o marca «Crear el ingrediente».");
                } elseif ($similar !== [] && ! $this->boolean('confirm_new')) {
                    $validator->errors()->add('ingredient', "«{$name}» se parece a un ingrediente que ya tienes.{$suggestion} Elige la sugerencia o confirma que es un ingrediente nuevo.");
                }
            },
        ];
    }

    public function resolvedIngredient(): ?Ingredient
    {
        return $this->resolved;
    }

    public function location(): StorageLocation
    {
        return StorageLocation::from($this->string('location')->toString());
    }

    public function quantity(): Quantity
    {
        return Quantity::of($this->string('quantity')->toString(), Unit::from($this->string('unit')->toString()));
    }

    public function category(): ?IngredientCategory
    {
        return $this->filled('category') ? IngredientCategory::from($this->string('category')->toString()) : null;
    }

    public function label(): ?string
    {
        $label = trim($this->string('label')->toString());

        return $label === '' ? null : $label;
    }
}
