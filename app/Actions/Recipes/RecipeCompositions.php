<?php

namespace App\Actions\Recipes;

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeIngredient;
use App\Models\RecipeRevision;
use App\Support\Quantity;
use Carbon\CarbonImmutable;

/**
 * The composition of a recipe at a moment (RN-45): its current lines, or those of the revision
 * that was in force then. A revision covers every moment up to and including its `valid_until`
 * (the moment of the edit), so a dish whose reference time is exactly the edit keeps the
 * earlier composition. Lines whose food or preparation no longer exists are left out.
 *
 * @phpstan-type Composition array{
 *     base_servings: int,
 *     lines: list<array{ingredient: Ingredient, quantity: Quantity, alternative_group: int|null}>,
 *     components: list<array{recipe: Recipe, servings: int}>
 * }
 */
class RecipeCompositions
{
    /** @var array<int, Ingredient|null> */
    private array $ingredients = [];

    /** @var array<int, Recipe|null> */
    private array $recipes = [];

    /**
     * @return Composition
     */
    public function at(Recipe $recipe, ?CarbonImmutable $moment = null): array
    {
        // Stored in the application timezone: compare the same instant, not the local clock time.
        $revision = $moment === null ? null : $recipe->revisions()
            ->where('valid_until', '>=', $moment->setTimezone((string) config('app.timezone')))
            ->first();

        return $revision === null ? $this->current($recipe) : $this->fromRevision($revision);
    }

    /**
     * @return Composition
     */
    public function current(Recipe $recipe): array
    {
        $recipe->loadMissing(['ingredients.ingredient', 'components.component']);
        $lines = [];

        foreach ($recipe->ingredients as $line) {
            /** @var RecipeIngredient $line */
            if ($line->ingredient !== null) {
                $lines[] = ['ingredient' => $line->ingredient, 'quantity' => $line->toQuantity(), 'alternative_group' => $line->alternative_group];
            }
        }

        $components = [];

        foreach ($recipe->components as $component) {
            /** @var RecipeComponent $component */
            $components[] = ['recipe' => $component->component, 'servings' => $component->servings];
        }

        return ['base_servings' => $recipe->base_servings, 'lines' => $lines, 'components' => $components];
    }

    /**
     * What a revision stores: the composition as plain data.
     *
     * @return array{base_servings: int, ingredients: list<array{ingredient_id: int, quantity: string, unit: string, alternative_group: int|null, position: int}>, components: list<array{component_recipe_id: int, servings: int, position: int}>}
     */
    public function snapshot(Recipe $recipe): array
    {
        $recipe->unsetRelation('ingredients')->unsetRelation('components');

        return [
            'base_servings' => $recipe->base_servings,
            'ingredients' => array_values($recipe->ingredients()->get()->map(fn (RecipeIngredient $line) => [
                'ingredient_id' => $line->ingredient_id,
                'quantity' => $line->quantity,
                'unit' => $line->unit->value,
                'alternative_group' => $line->alternative_group,
                'position' => $line->position,
            ])->all()),
            'components' => array_values($recipe->components()->get()->map(fn (RecipeComponent $component) => [
                'component_recipe_id' => $component->component_recipe_id,
                'servings' => $component->servings,
                'position' => $component->position,
            ])->all()),
        ];
    }

    /**
     * @return Composition
     */
    private function fromRevision(RecipeRevision $revision): array
    {
        $lines = [];

        foreach ($revision->ingredients as $line) {
            $ingredient = $this->ingredients[$line['ingredient_id']] ??= Ingredient::query()->find($line['ingredient_id']);

            if ($ingredient !== null && is_numeric($line['quantity'])) {
                $lines[] = [
                    'ingredient' => $ingredient,
                    'quantity' => Quantity::of($line['quantity'], Unit::from($line['unit'])),
                    'alternative_group' => $line['alternative_group'],
                ];
            }
        }

        $components = [];

        foreach ($revision->components as $component) {
            $recipe = $this->recipes[$component['component_recipe_id']] ??= Recipe::query()->find($component['component_recipe_id']);

            if ($recipe !== null) {
                $components[] = ['recipe' => $recipe, 'servings' => $component['servings']];
            }
        }

        return ['base_servings' => $revision->base_servings, 'lines' => $lines, 'components' => $components];
    }
}
