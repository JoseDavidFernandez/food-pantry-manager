<?php

namespace Database\Seeders\Concerns;

use App\Models\Household;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Development seeders only: delete the functional data of ONE household (ingredients, aliases,
 * stock, recipes, menus, shopping lists and receptions). Users, households and memberships are
 * never touched, and every query is filtered by the household.
 */
trait CleansHouseholdData
{
    /**
     * Ingredients may only be referenced from rows of the same household; if not, stop.
     */
    protected function ensureNothingOutsideReferencesTheHousehold(Household $household): void
    {
        $ingredients = DB::table('ingredients')->where('household_id', $household->id)->select('id');

        $foreign = DB::table('inventory_items')->whereIn('ingredient_id', $ingredients)->where('household_id', '<>', $household->id)->count()
            + DB::table('ingredient_aliases')->whereIn('ingredient_id', $ingredients)->where('household_id', '<>', $household->id)->count()
            + DB::table('recipe_ingredients')->whereIn('ingredient_id', $ingredients)
                ->whereNotIn('recipe_id', DB::table('recipes')->where('household_id', $household->id)->select('id'))->count()
            + DB::table('planned_dish_ingredients')->whereIn('ingredient_id', $ingredients)
                ->whereNotIn('planned_dish_id', $this->dishes($household))->count()
            + DB::table('shopping_list_items')->whereIn('ingredient_id', $ingredients)
                ->whereNotIn('shopping_list_id', DB::table('shopping_lists')->where('household_id', $household->id)->select('id'))->count()
            + DB::table('reception_items')->whereIn('ingredient_id', $ingredients)
                ->whereNotIn('reception_id', DB::table('receptions')->where('household_id', $household->id)->select('id'))->count();

        if ($foreign > 0) {
            throw new RuntimeException("Hay {$foreign} filas de otros hogares que usan ingredientes de este hogar. No se borra nada.");
        }
    }

    /**
     * Delete the household's functional data, children first. Every query is filtered by household.
     *
     * @return array<string, int>
     */
    protected function clean(Household $household): array
    {
        $id = $household->id;
        $receptions = DB::table('receptions')->where('household_id', $id)->select('id');
        $lists = DB::table('shopping_lists')->where('household_id', $id)->select('id');
        $recipes = DB::table('recipes')->where('household_id', $id)->select('id');

        return [
            'reception_items' => DB::table('reception_items')->whereIn('reception_id', $receptions)->delete(),
            'receptions' => DB::table('receptions')->where('household_id', $id)->delete(),
            'shopping_list_items' => DB::table('shopping_list_items')->whereIn('shopping_list_id', $lists)->delete(),
            'shopping_lists' => DB::table('shopping_lists')->where('household_id', $id)->delete(),
            'planned_dish_ingredients' => DB::table('planned_dish_ingredients')->whereIn('planned_dish_id', $this->dishes($household))->delete(),
            'planned_dishes' => DB::table('planned_dishes')->whereIn('menu_id', DB::table('menus')->where('household_id', $id)->select('id'))->delete(),
            'menus' => DB::table('menus')->where('household_id', $id)->delete(),
            'recipe_ingredients' => DB::table('recipe_ingredients')->whereIn('recipe_id', $recipes)->delete(),
            'recipes' => DB::table('recipes')->where('household_id', $id)->delete(),
            'inventory_items' => DB::table('inventory_items')->where('household_id', $id)->delete(),
            'ingredient_aliases' => DB::table('ingredient_aliases')->where('household_id', $id)->delete(),
            'ingredients' => DB::table('ingredients')->where('household_id', $id)->delete(),
        ];
    }

    protected function dishes(Household $household): Builder
    {
        return DB::table('planned_dishes')
            ->whereIn('menu_id', DB::table('menus')->where('household_id', $household->id)->select('id'))
            ->select('id');
    }

    /**
     * What clean() would delete, without deleting anything.
     *
     * @return array<string, int>
     */
    protected function householdDataCounts(Household $household): array
    {
        $id = $household->id;

        return [
            'reception_items' => DB::table('reception_items')->whereIn('reception_id', DB::table('receptions')->where('household_id', $id)->select('id'))->count(),
            'receptions' => DB::table('receptions')->where('household_id', $id)->count(),
            'shopping_list_items' => DB::table('shopping_list_items')->whereIn('shopping_list_id', DB::table('shopping_lists')->where('household_id', $id)->select('id'))->count(),
            'shopping_lists' => DB::table('shopping_lists')->where('household_id', $id)->count(),
            'planned_dish_ingredients' => DB::table('planned_dish_ingredients')->whereIn('planned_dish_id', $this->dishes($household))->count(),
            'planned_dishes' => $this->dishes($household)->count(),
            'menus' => DB::table('menus')->where('household_id', $id)->count(),
            'recipe_ingredients' => DB::table('recipe_ingredients')->whereIn('recipe_id', DB::table('recipes')->where('household_id', $id)->select('id'))->count(),
            'recipes' => DB::table('recipes')->where('household_id', $id)->count(),
            'inventory_items' => DB::table('inventory_items')->where('household_id', $id)->count(),
            'ingredient_aliases' => DB::table('ingredient_aliases')->where('household_id', $id)->count(),
            'ingredients' => DB::table('ingredients')->where('household_id', $id)->count(),
        ];
    }
}
