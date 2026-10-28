<?php

namespace App\Actions\Ingredients;

use App\Enums\IngredientCategory;
use App\Models\Household;
use App\Support\IngredientName;

/**
 * Usual foods offered in «Sin existencias» (RN-49): the names of the category dictionary
 * (`config/ingredient_categories.php`) that the household does not have yet. Choosing one only
 * creates the food through the usual creation (RN-03, RN-04); nothing is added to the stock.
 */
class BasicFoods
{
    /**
     * Suggestions in the order of the shopping aisles, alphabetical within each category. A name
     * is left out when it, or its singular, is already a name or an alias of the household, or
     * the singular of one (Tomates hides Tomate; an alias Huevos hides Huevo).
     *
     * @return list<array{name: string, category: string, category_label: string}>
     */
    public function suggestions(Household $household): array
    {
        $taken = [];

        $existing = $household->ingredients()->pluck('normalized_name')
            ->merge($household->ingredientAliases()->pluck('normalized_alias'));

        foreach ($existing as $normalized) {
            foreach (IngredientName::withSingulars($normalized) as $form) {
                $taken[$form] = true;
            }
        }

        $suggestions = [];

        foreach (IngredientCategory::cases() as $category) {
            /** @var list<string> $names */
            $names = config("ingredient_categories.{$category->value}", []);
            $free = [];

            foreach ($names as $name) {
                $normalized = IngredientName::normalize($name);

                foreach (IngredientName::withSingulars($normalized) as $form) {
                    if (isset($taken[$form])) {
                        continue 2;
                    }
                }

                $free[$normalized] = mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
            }

            ksort($free, SORT_STRING);

            foreach ($free as $name) {
                $suggestions[] = ['name' => $name, 'category' => $category->value, 'category_label' => $category->label()];
            }
        }

        return $suggestions;
    }
}
