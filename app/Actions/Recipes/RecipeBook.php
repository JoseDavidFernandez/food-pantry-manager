<?php

namespace App\Actions\Recipes;

use App\Actions\Ingredients\IngredientCatalog;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\PlannedDish;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Household recipe book (RN-12 to RN-14). Recipes referenced by menus are archived, never deleted.
 */
class RecipeBook
{
    public function __construct(
        private readonly IngredientCatalog $catalog,
        private readonly RecipeCompositions $compositions,
    ) {}

    /**
     * A string ingredient is a new one the user explicitly asked to create; it is created here,
     * in the same transaction as the recipe (RN-03). A row marked as `alternative` is «or» of the
     * row before it (RN-42); components are preparations included in the recipe (RN-43).
     *
     * @param  array{name: string, base_servings: int, instructions: string|null}  $attributes
     * @param  list<array{ingredient: Ingredient|string, quantity: Quantity, note: string|null, alternative?: bool}>  $rows
     * @param  list<array{recipe: Recipe, servings: int}>  $components
     *
     * @throws RecipeCycle when a preparation includes, directly or not, this recipe
     */
    public function save(Household $household, ?Recipe $recipe, array $attributes, array $rows, array $components = []): Recipe
    {
        return DB::transaction(function () use ($household, $recipe, $attributes, $rows, $components) {
            // Recipes of the household are changed one after the other, so two simultaneous
            // edits cannot include each other.
            $household->recipes()->lockForUpdate()->pluck('id');

            foreach ($components as $component) {
                if ($recipe !== null && $this->includes($component['recipe'], $recipe)) {
                    throw new RecipeCycle($component['recipe']);
                }
            }

            // RN-45: what the recipe was before this edit, in case dishes already used it.
            $before = $recipe?->exists ? $this->compositions->snapshot($recipe) : null;

            $recipe ??= new Recipe(['household_id' => $household->id]);
            $recipe->fill($attributes)->save();

            $recipe->ingredients()->delete();
            $groups = $this->alternativeGroups($rows);

            foreach ($rows as $position => $row) {
                $ingredient = $row['ingredient'] instanceof Ingredient
                    ? $row['ingredient']
                    : $this->catalog->findOrCreate($household, $row['ingredient']);

                $line = $recipe->ingredients()->make([
                    'ingredient_id' => $ingredient->id,
                    'note' => $row['note'],
                    'position' => $position,
                    'alternative_group' => $groups[$position] ?? null,
                ]);
                $line->setQuantity($row['quantity']);
                $line->save();
            }

            $recipe->components()->delete();

            foreach ($components as $position => $component) {
                $recipe->components()->create([
                    'component_recipe_id' => $component['recipe']->id,
                    'servings' => $component['servings'],
                    'position' => $position,
                ]);
            }

            if ($before !== null && $before !== $this->compositions->snapshot($recipe) && $this->hasPastDishes($recipe)) {
                $recipe->revisions()->create([...$before, 'valid_until' => CarbonImmutable::now()]);
            }

            return $recipe;
        });
    }

    /**
     * Whether a dish that already took place (its reference time has passed) used this recipe,
     * directly or through a recipe that includes it as a preparation (RN-45).
     */
    public function hasPastDishes(Recipe $recipe): bool
    {
        $ids = [$recipe->id];
        $frontier = [$recipe->id];

        while ($frontier !== []) {
            $frontier = array_values(array_diff(
                RecipeComponent::query()->whereIn('component_recipe_id', $frontier)->pluck('recipe_id')->all(),
                $ids,
            ));
            $ids = [...$ids, ...$frontier];
        }

        $now = CarbonImmutable::now();

        return PlannedDish::query()
            ->whereIn('recipe_id', $ids)
            ->whereHas('menu', fn ($query) => $query->whereDate('week_start', '<=', $now->format('Y-m-d')))
            ->with('menu')
            ->get()
            ->contains(fn (PlannedDish $dish) => $dish->referenceTime()->lessThanOrEqualTo($now));
    }

    /**
     * Whether $recipe is $target or includes it through its preparations, at any depth.
     */
    public function includes(Recipe $recipe, Recipe $target): bool
    {
        $pending = [$recipe->id];
        $seen = [];

        while ($pending !== []) {
            $id = array_pop($pending);

            if ($id === $target->id) {
                return true;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $pending = [...$pending, ...RecipeComponent::query()->where('recipe_id', $id)->pluck('component_recipe_id')->all()];
        }

        return false;
    }

    /**
     * Group number per row position: a row marked as alternative joins the group of the row
     * before it (which starts one if it had none).
     *
     * @param  list<array{alternative?: bool}>  $rows
     * @return array<int, int>
     */
    private function alternativeGroups(array $rows): array
    {
        $groups = [];
        $next = 1;

        foreach ($rows as $position => $row) {
            if (($row['alternative'] ?? false) && $position > 0) {
                $groups[$position - 1] ??= $next++;
                $groups[$position] = $groups[$position - 1];
            }
        }

        return $groups;
    }

    /**
     * RN-14 and RN-43: used in a menu or included in another recipe.
     */
    public function isReferenced(Recipe $recipe): bool
    {
        return $recipe->plannedDishes()->exists() || $recipe->usedIn()->exists();
    }

    public function archive(Recipe $recipe): void
    {
        $recipe->forceFill(['archived_at' => now()])->save();
    }

    public function restore(Recipe $recipe): void
    {
        $recipe->forceFill(['archived_at' => null])->save();
    }

    /**
     * Only unreferenced recipes can be deleted (RN-14); callers check isReferenced() first.
     */
    public function delete(Recipe $recipe): void
    {
        DB::transaction(function () use ($recipe) {
            $recipe->ingredients()->delete();
            $recipe->components()->delete();
            $recipe->delete();
        });
    }
}
