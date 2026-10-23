<?php

namespace App\Http\Requests\Menus;

use App\Enums\DishType;
use App\Http\Requests\Concerns\ValidatesIngredientRows;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Put a dish in a menu slot: a saved, non-archived recipe (RN-14) or a one-off dish whose
 * ingredients may be completed later (RN-17, USER-FLOWS §4).
 */
class AssignDishRequest extends FormRequest
{
    use ValidatesIngredientRows;

    private ?Recipe $recipe = null;

    protected function prepareForValidation(): void
    {
        $this->prepareIngredientRows();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DishType::class)],
            'recipe_id' => ['required_if:type,recipe', 'nullable', 'integer'],
            'name' => ['required_if:type,adhoc', 'nullable', 'string', 'max:120'],
            'servings' => ['required', 'integer', 'min:1', 'max:100'],
            ...$this->ingredientRowRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'recipe_id' => 'receta',
            'name' => 'nombre del plato',
            'servings' => 'raciones',
            ...$this->ingredientRowAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipe_id.required_if' => 'Elige una receta.',
            'name.required_if' => 'Escribe el nombre del plato.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var User $user */
                $user = $this->user();
                $household = $user->currentHousehold();

                if ($this->dishType() === DishType::Recipe) {
                    if ($validator->errors()->has('recipe_id')) {
                        return;
                    }

                    $this->recipe = $household->recipes()->active()->find($this->integer('recipe_id'));

                    if ($this->recipe === null) {
                        $validator->errors()->add('recipe_id', 'Elige una receta activa de tu recetario.');
                    }

                    return;
                }

                $this->resolveIngredientRows($validator, $household);
            },
        ];
    }

    public function dishType(): DishType
    {
        return DishType::tryFrom($this->string('type')->toString()) ?? DishType::Adhoc;
    }

    public function recipe(): ?Recipe
    {
        return $this->recipe;
    }
}
