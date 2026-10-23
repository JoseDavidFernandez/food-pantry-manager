<?php

namespace App\Actions\Ingredients;

use App\Actions\Ingredients\Images\IngredientImages;
use App\Enums\IngredientCategory;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\ReceptionItem;
use App\Models\ShoppingListItem;
use App\Support\IngredientName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Ingredient catalog of a household: normalized names and explicit aliases (RN-03, RN-04).
 * Ingredients are never merged automatically by similarity.
 */
class IngredientCatalog
{
    public function __construct(private readonly CategoryGuesser $categories) {}

    /**
     * Resolve a typed text to an ingredient by normalized name, then by alias.
     */
    public function resolve(Household $household, string $text): ?Ingredient
    {
        $normalized = IngredientName::normalize($text);

        if ($normalized === '') {
            return null;
        }

        $ingredient = $household->ingredients()->where('normalized_name', $normalized)->first();

        if ($ingredient !== null) {
            return $ingredient;
        }

        $alias = $household->ingredientAliases()->with('ingredient')->where('normalized_alias', $normalized)->first();

        return $alias?->ingredient;
    }

    /**
     * Names and aliases offered while typing an ingredient, alphabetically.
     *
     * @return list<string>
     */
    public function suggestions(Household $household): array
    {
        return array_values($household->ingredients()->get(['name'])->map(fn (Ingredient $ingredient) => $ingredient->name)
            ->merge($household->ingredientAliases()->get(['alias'])->map(fn (IngredientAlias $alias) => $alias->alias))
            ->unique()
            ->sort(fn (string $a, string $b) => strcoll($a, $b))
            ->all());
    }

    /**
     * Ingredients whose name or alias looks like the typed text (typos, partial names), best first.
     * Only a suggestion: callers must never assign one without the user's confirmation (RN-04).
     *
     * @return list<array{id: int, name: string, via: string|null}>
     */
    public function similar(Household $household, string $text, int $limit = 3): array
    {
        $needle = IngredientName::normalize($text);

        if (mb_strlen($needle) < 3) {
            return [];
        }

        $candidates = [];

        foreach ($household->ingredients()->get(['id', 'name', 'normalized_name']) as $ingredient) {
            $candidates[] = [$ingredient->id, $ingredient->name, $ingredient->normalized_name, null];
        }

        foreach ($household->ingredientAliases()->with('ingredient:id,name')->get() as $alias) {
            $candidates[] = [$alias->ingredient_id, $alias->ingredient->name, $alias->normalized_alias, $alias->alias];
        }

        $best = [];

        foreach ($candidates as [$id, $name, $normalized, $via]) {
            $score = $this->similarity($needle, $normalized);

            if ($score === null) {
                continue;
            }

            // On a tie, keep the longer (more specific) match, e.g. an alias over a shorter name.
            $rank = [$score, mb_strlen($normalized)];

            if (! isset($best[$id]) || $rank > $best[$id]['rank']) {
                $best[$id] = ['id' => $id, 'name' => $name, 'via' => $via, 'score' => $score, 'rank' => $rank];
            }
        }

        usort($best, fn (array $a, array $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return array_map(
            fn (array $match) => ['id' => $match['id'], 'name' => $match['name'], 'via' => $match['via']],
            array_slice($best, 0, $limit),
        );
    }

    /**
     * Score (0-100) when two normalized names are likely the same ingredient, or null.
     */
    private function similarity(string $needle, string $candidate): ?float
    {
        if ($needle === $candidate) {
            return null;
        }

        if (mb_strlen($candidate) >= 3 && (str_contains($candidate, $needle) || str_contains($needle, $candidate))) {
            return 100.0;
        }

        $distance = levenshtein($needle, $candidate);
        similar_text($needle, $candidate, $percent);

        if ($distance <= 2 || $percent >= 75) {
            return max($percent, 100 - $distance * 10);
        }

        return null;
    }

    /**
     * Describe why a term cannot be used as a new name or alias, or null when it is free.
     */
    public function conflict(Household $household, string $term, ?Ingredient $ignore = null): ?string
    {
        $normalized = IngredientName::normalize($term);

        $ingredient = $household->ingredients()
            ->where('normalized_name', $normalized)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->first();

        if ($ingredient !== null) {
            return "Ya existe el alimento «{$ingredient->name}».";
        }

        $alias = $household->ingredientAliases()->with('ingredient')->where('normalized_alias', $normalized)->first();

        if ($alias !== null) {
            return "«{$alias->alias}» ya es un alias de «{$alias->ingredient->name}».";
        }

        return null;
    }

    /**
     * The ingredient the user explicitly asked to create (RN-03), or the one that already has that
     * normalized name or alias. Safe against two requests creating the same name at once: the
     * unique index wins and the losing request reuses the row the other one created.
     */
    public function findOrCreate(Household $household, string $name, ?IngredientCategory $category = null): Ingredient
    {
        $existing = $this->resolve($household, $name);

        if ($existing !== null) {
            return $existing;
        }

        try {
            // A savepoint when called inside another transaction, so the outer one can continue.
            return DB::transaction(fn () => $this->create($household, $name, null, $category));
        } catch (UniqueConstraintViolationException $exception) {
            // Locking read: sees the row committed by the concurrent request, not an old snapshot.
            return $household->ingredients()
                ->where('normalized_name', IngredientName::normalize($name))
                ->sharedLock()
                ->first() ?? throw $exception;
        }
    }

    /**
     * A category chosen by the user is kept; without one, the local dictionary may assign it
     * (RN-31) and otherwise the food stays «Sin clasificar».
     */
    public function create(Household $household, string $name, ?Unit $defaultUnit = null, ?IngredientCategory $category = null): Ingredient
    {
        return $household->ingredients()->create([
            'name' => IngredientName::clean($name),
            'normalized_name' => IngredientName::normalize($name),
            'default_unit' => $defaultUnit,
            'category' => $category ?? $this->categories->guess($name),
        ]);
    }

    /**
     * Rename, change the usual unit and the category. Only what the user chose: editing never
     * infers a category, so «Sin clasificar» can be chosen on purpose.
     */
    public function update(Ingredient $ingredient, string $name, ?Unit $defaultUnit, ?IngredientCategory $category = null): Ingredient
    {
        $ingredient->update([
            'name' => IngredientName::clean($name),
            'normalized_name' => IngredientName::normalize($name),
            'default_unit' => $defaultUnit,
            'category' => $category,
        ]);

        return $ingredient;
    }

    public function addAlias(Ingredient $ingredient, string $alias): IngredientAlias
    {
        return $ingredient->aliases()->create([
            'household_id' => $ingredient->household_id,
            'alias' => IngredientName::clean($alias),
            'normalized_alias' => IngredientName::normalize($alias),
        ]);
    }

    /**
     * Ingredients in use (stock, recipes or planned dishes) cannot be deleted (DATA-MODEL §7).
     */
    public function canDelete(Ingredient $ingredient): bool
    {
        return $this->usage($ingredient) === null;
    }

    /**
     * Where the ingredient is used, in Spanish, or null when it is unused.
     */
    public function usage(Ingredient $ingredient): ?string
    {
        $uses = array_keys(array_filter([
            'el inventario' => $ingredient->inventoryItems()->exists(),
            'recetas' => $ingredient->recipeIngredients()->exists(),
            'menús' => $ingredient->plannedDishIngredients()->exists(),
            'listas de la compra' => ShoppingListItem::query()->where('ingredient_id', $ingredient->id)->exists(),
            'recepciones' => ReceptionItem::query()->where('ingredient_id', $ingredient->id)->exists(),
        ]));

        return $uses === [] ? null : implode(', ', $uses);
    }

    public function delete(Ingredient $ingredient): void
    {
        $image = $ingredient->image_path;

        DB::transaction(function () use ($ingredient) {
            $ingredient->aliases()->delete();
            $ingredient->delete();
        });

        if ($image !== null) {
            Storage::disk(IngredientImages::DISK)->delete($image);
        }
    }
}
