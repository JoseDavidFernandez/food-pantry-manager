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

        foreach ($this->candidates(IngredientName::normalize($name)) as $candidate) {
            if (isset($index[$candidate])) {
                return $index[$candidate];
            }
        }

        return null;
    }

    /**
     * The name itself and its singular forms (tomates → tomate, limones → limon).
     *
     * @return list<string>
     */
    private function candidates(string $name): array
    {
        $candidates = [$name];

        if (str_ends_with($name, 'es')) {
            $candidates[] = substr($name, 0, -2);
        }

        if (str_ends_with($name, 's')) {
            $candidates[] = substr($name, 0, -1);
        }

        return $candidates;
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
