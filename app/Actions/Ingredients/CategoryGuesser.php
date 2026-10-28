<?php

namespace App\Actions\Ingredients;

use App\Enums\IngredientCategory;
use App\Support\IngredientName;

/**
 * Automatic category of a new food (RN-31): local, deterministic and offline. A name counts
 * only when it is in the dictionary (`config/ingredient_categories.php`), compared without case,
 * accents or extra spaces, also in plural. Anything else stays «Sin clasificar»: no guessing
 * from partial words.
 */
class CategoryGuesser
{
    /** @var array<string, IngredientCategory>|null */
    private ?array $index = null;

    public function guess(string $name): ?IngredientCategory
    {
        $index = $this->index();

        foreach (IngredientName::withSingulars(IngredientName::normalize($name)) as $candidate) {
            if (isset($index[$candidate])) {
                return $index[$candidate];
            }
        }

        return null;
    }

    /**
     * @return array<string, IngredientCategory>
     */
    private function index(): array
    {
        if ($this->index === null) {
            $this->index = [];

            /** @var array<string, list<string>> $dictionary */
            $dictionary = config('ingredient_categories', []);

            foreach ($dictionary as $category => $names) {
                foreach ($names as $name) {
                    $this->index[IngredientName::normalize($name)] = IngredientCategory::from($category);
                }
            }
        }

        return $this->index;
    }
}
