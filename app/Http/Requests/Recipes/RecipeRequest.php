<?php

namespace App\Http\Requests\Recipes;

use App\Actions\Recipes\RecipeBook;
use App\Http\Requests\Concerns\ValidatesIngredientRows;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Create or edit a recipe with base servings and catalog ingredients (RN-12).
 */
class RecipeRequest extends FormRequest
{
    use ValidatesIngredientRows;

    public function authorize(): bool
    {
        $recipe = $this->route('recipe');

        return ! $recipe instanceof Recipe || $this->user()?->can('update', $recipe) === true;
    }

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
            'name' => ['required', 'string', 'max:120'],
            'base_servings' => ['required', 'integer', 'min:1', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            ...$this->ingredientRowRules(),
            // RN-43: preparations included (another recipe and how many of its servings).
            'components' => ['array', 'max:10'],
            'components.*.recipe_id' => ['required', 'integer', 'distinct'],
            'components.*.servings' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'base_servings' => 'raciones',
            'instructions' => 'preparación',
            ...$this->ingredientRowAttributes(),
            'components.*.recipe_id' => 'preparación incluida',
            'components.*.servings' => 'raciones de la preparación',
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
                $this->resolveIngredientRows($validator, $user->currentHousehold());
                $this->resolveComponents($validator, $user);
            },
        ];
    }

    /**
     * @var list<array{recipe: Recipe, servings: int}>
     */
    private array $resolvedComponents = [];

    /**
     * Preparations of this household only; never the recipe itself nor one that includes it.
     */
    private function resolveComponents(Validator $validator, User $user): void
    {
        $book = app(RecipeBook::class);
        $current = $this->route('recipe');
        $household = $user->currentHousehold();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $this->input('components', []);

        foreach ($rows as $index => $row) {
            if ($validator->errors()->has("components.{$index}.*")) {
                continue;
            }

            $component = $household->recipes()->find((int) $row['recipe_id']);

            if (! $component instanceof Recipe) {
                $validator->errors()->add("components.{$index}.recipe_id", 'Esa receta no está en tu recetario.');

                continue;
            }

            if ($current instanceof Recipe && $book->includes($component, $current)) {
                $validator->errors()->add("components.{$index}.recipe_id", $component->is($current)
                    ? 'Una receta no puede incluirse a sí misma.'
                    : "«{$component->name}» ya incluye esta receta: se formaría un ciclo.");

                continue;
            }

            $this->resolvedComponents[] = ['recipe' => $component, 'servings' => (int) $row['servings']];
        }
    }

    /**
     * @return list<array{recipe: Recipe, servings: int}>
     */
    public function components(): array
    {
        return $this->resolvedComponents;
    }

    /**
     * @return array{name: string, base_servings: int, instructions: string|null}
     */
    public function recipeAttributes(): array
    {
        $instructions = trim($this->string('instructions')->toString());

        return [
            'name' => trim($this->string('name')->toString()),
            'base_servings' => $this->integer('base_servings'),
            'instructions' => $instructions === '' ? null : $instructions,
        ];
    }
}
