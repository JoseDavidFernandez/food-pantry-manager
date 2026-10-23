<?php

namespace App\Http\Requests\Ingredients;

use App\Actions\Ingredients\Images\CommonsImages;
use App\Actions\Ingredients\Images\OpenFoodFactsImages;
use App\Actions\Ingredients\IngredientCatalog;
use App\Enums\IngredientCategory;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or rename an ingredient (RN-03, RN-04).
 */
class IngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ingredient = $this->ingredient();

        return $ingredient === null || $this->user()?->can('update', $ingredient) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'default_unit' => ['nullable', Rule::enum(Unit::class)],
            'category' => ['nullable', Rule::enum(IngredientCategory::class)],
            // An image chosen in the form; resolved again on the server from the reference.
            'image_provider' => ['nullable', Rule::in([CommonsImages::NAME, OpenFoodFactsImages::NAME])],
            'image_reference' => ['nullable', 'required_with:image_provider', 'string', 'max:255'],
            'confirm_new' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('name')) {
                    return;
                }

                /** @var User $user */
                $user = $this->user();
                $catalog = app(IngredientCatalog::class);
                $household = $user->currentHousehold();
                $conflict = $catalog->conflict($household, $this->string('name')->toString(), $this->ingredient());

                if ($conflict !== null) {
                    $validator->errors()->add('name', $conflict);

                    return;
                }

                // Creating: warn about likely duplicates (typos, plurals) unless the user confirms.
                if ($this->ingredient() === null && ! $this->boolean('confirm_new')) {
                    $similar = array_column($catalog->similar($household, $this->string('name')->toString()), 'name');

                    if ($similar !== []) {
                        $validator->errors()->add('name', 'Se parece a «'.implode('», «', $similar).'». Si es un plural o un sinónimo, añádelo como alias; si es otro ingrediente, confirma que quieres crearlo.');
                    }
                }
            },
        ];
    }

    /**
     * @return array{provider: string, reference: string}|null
     */
    public function chosenImage(): ?array
    {
        return $this->filled('image_provider') && $this->filled('image_reference')
            ? ['provider' => $this->string('image_provider')->toString(), 'reference' => $this->string('image_reference')->toString()]
            : null;
    }

    public function category(): ?IngredientCategory
    {
        return $this->filled('category') ? IngredientCategory::from($this->string('category')->toString()) : null;
    }

    public function defaultUnit(): ?Unit
    {
        return $this->filled('default_unit') ? Unit::from($this->string('default_unit')->toString()) : null;
    }

    private function ingredient(): ?Ingredient
    {
        $ingredient = $this->route('ingredient');

        return $ingredient instanceof Ingredient ? $ingredient : null;
    }
}
