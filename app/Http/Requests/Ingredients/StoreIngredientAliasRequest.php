<?php

namespace App\Http\Requests\Ingredients;

use App\Actions\Ingredients\IngredientCatalog;
use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add an explicit alias to an ingredient (RN-04).
 */
class StoreIngredientAliasRequest extends FormRequest
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
            'alias' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('alias')) {
                    return;
                }

                $ingredient = $this->ingredient();
                $conflict = app(IngredientCatalog::class)->conflict($ingredient->household, $this->string('alias')->toString());

                if ($conflict !== null) {
                    $validator->errors()->add('alias', $conflict);
                }
            },
        ];
    }

    public function ingredient(): Ingredient
    {
        /** @var Ingredient $ingredient */
        $ingredient = $this->route('ingredient');

        return $ingredient;
    }
}
