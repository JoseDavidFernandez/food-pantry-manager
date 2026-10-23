<?php

namespace App\Actions\Recipes;

use App\Models\Ingredient;
use App\Models\PlannedDish;
use App\Models\PlannedDishChoice;
use App\Models\Recipe;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use App\Support\StockAllocation;
use Carbon\CarbonImmutable;

/**
 * The ingredients a recipe really needs for some servings (DATA-MODEL §6.1):
 * - its own lines, scaled (RN-13);
 * - the lines of the preparations it includes (RN-43), scaled by the servings of the
 *   preparation it uses and by the servings of the dish, in one single step (no rounding in
 *   between); a preparation is expanded where it is used, so it never duplicates anything;
 * - one option per group of alternative ingredients (RN-42): the one the user chose for the
 *   dish or, until then, a proposal. Proposals use the stock still unassigned (StockAllocation):
 *   the dish first takes its fixed lines and confirmed choices, then each proposal is the first
 *   option that stock covers, else the first one with some of it, else the first one. A
 *   proposal is always shown as such, never applied silently. A choice whose option left the
 *   recipe is reported as stale and proposed again.
 *
 * Cycles are refused when saving; the expansion also stops at a recipe already being expanded.
 *
 * @phpstan-type Choice array{recipe_id: int, recipe: string, group: int, chosen: int, confirmed: bool, stale: string|null, options: list<array{ingredient_id: int, name: string, quantity: string, in_stock: bool}>}
 * @phpstan-type Line array{ingredient: Ingredient, quantity: Quantity, choice: Choice|null}
 * @phpstan-type Group array{recipe: Recipe, group: int, options: list<array{0: Ingredient, 1: Quantity}>, chosen: int|null}
 */
class RecipeExpansion
{
    public const MAX_DEPTH = 5;

    public function __construct(private readonly RecipeCompositions $compositions) {}

    /**
     * What a planned dish needs, taking its share of the stock from $stock. With a moment (the
     * reference time of a dish that already took place), each recipe is taken as it was then
     * (RN-45), so editing a recipe never changes what past dishes used.
     *
     * @return list<Line>
     */
    public function forDish(PlannedDish $dish, StockAllocation $stock, FoodUnitsMap $units, ?CarbonImmutable $moment = null): array
    {
        $recipe = $dish->recipe;

        if ($recipe === null) {
            return [];
        }

        $choices = [];
        $names = [];

        foreach ($dish->choices()->with('ingredient')->get() as $choice) {
            /** @var PlannedDishChoice $choice */
            $choices[$choice->recipe_id.':'.$choice->alternative_group] = $choice->ingredient_id;
            $names[$choice->recipe_id.':'.$choice->alternative_group] = $choice->ingredient?->name;
        }

        $base = $this->compositions->at($recipe, $moment)['base_servings'];

        return $this->resolve($this->expand($recipe, $dish->servings, $base, $choices, [], $moment), $choices, $names, $stock, $units);
    }

    /**
     * Lines of a recipe for some servings, without a planned dish (the recipe page): proposals
     * are the first option of each group.
     *
     * @return list<Line>
     */
    public function forServings(Recipe $recipe, int $servings, FoodUnitsMap $units): array
    {
        return $this->resolve($this->expand($recipe, $servings, $recipe->base_servings, [], []), [], [], StockAllocation::none(), $units);
    }

    /**
     * Fixed lines and confirmed choices take their stock first; then each proposal is decided
     * with what is left and takes its share. The order of the lines is kept.
     *
     * @param  list<array{ingredient: Ingredient, quantity: Quantity}|Group>  $entries
     * @param  array<string, int>  $choices
     * @param  array<string, string|null>  $names
     * @return list<Line>
     */
    private function resolve(array $entries, array $choices, array $names, StockAllocation $stock, FoodUnitsMap $units): array
    {
        $lines = [];
        $proposals = [];

        foreach ($entries as $index => $entry) {
            if (! isset($entry['group'])) {
                $lines[$index] = ['ingredient' => $entry['ingredient'], 'quantity' => $entry['quantity'], 'choice' => null];
                $this->take($stock, $units, $entry['ingredient'], $entry['quantity']);

                continue;
            }

            $confirmed = null;

            foreach ($entry['options'] as $option => [$ingredient]) {
                if ($ingredient->id === $entry['chosen']) {
                    $confirmed = $option;
                }
            }

            if ($confirmed === null) {
                $proposals[$index] = $entry;

                continue;
            }

            $lines[$index] = $this->line($entry, $confirmed, true, null, $stock, $units);
            $this->take($stock, $units, $lines[$index]['ingredient'], $lines[$index]['quantity']);
        }

        foreach ($proposals as $index => $entry) {
            $covered = null;
            $some = null;

            foreach ($entry['options'] as $option => [$ingredient, $quantity]) {
                [$key, $normalized] = $units->keyed($ingredient->id, $quantity);
                $covered ??= $stock->covers($key, $normalized) ? $option : null;
                $some ??= $stock->available($key) !== null ? $option : null;
            }

            $groupKey = $entry['recipe']->id.':'.$entry['group'];
            // A choice whose ingredient left the group (the recipe was edited) is proposed again.
            $stale = isset($choices[$groupKey]) ? ($names[$groupKey] ?? 'la opción elegida') : null;
            $lines[$index] = $this->line($entry, $covered ?? $some ?? 0, false, $stale, $stock, $units);
            $this->take($stock, $units, $lines[$index]['ingredient'], $lines[$index]['quantity']);
        }

        ksort($lines);

        return array_values($lines);
    }

    /**
     * @param  Group  $entry
     * @return Line
     */
    private function line(array $entry, int $index, bool $confirmed, ?string $stale, StockAllocation $stock, FoodUnitsMap $units): array
    {
        [$ingredient, $quantity] = $entry['options'][$index];

        return [
            'ingredient' => $ingredient,
            'quantity' => $quantity,
            'choice' => [
                'recipe_id' => $entry['recipe']->id,
                'recipe' => $entry['recipe']->name,
                'group' => $entry['group'],
                'chosen' => $ingredient->id,
                'confirmed' => $confirmed,
                'stale' => $stale,
                // «En casa»: what is still unassigned covers it, before this dish takes it.
                'options' => array_map(function (array $option) use ($stock, $units) {
                    [$key, $normalized] = $units->keyed($option[0]->id, $option[1]);

                    return [
                        'ingredient_id' => $option[0]->id,
                        'name' => $option[0]->name,
                        'quantity' => $option[1]->readable(),
                        'in_stock' => $stock->covers($key, $normalized),
                    ];
                }, $entry['options']),
            ],
        ];
    }

    private function take(StockAllocation $stock, FoodUnitsMap $units, Ingredient $ingredient, Quantity $quantity): void
    {
        [$key, $normalized] = $units->keyed($ingredient->id, $quantity);
        $stock->take($key, $normalized);
    }

    /**
     * @param  array<string, int>  $choices
     * @param  list<int>  $visited
     * @return list<array{ingredient: Ingredient, quantity: Quantity}|Group>
     */
    private function expand(Recipe $recipe, int $numerator, int $denominator, array $choices, array $visited, ?CarbonImmutable $moment = null): array
    {
        if (in_array($recipe->id, $visited, true) || count($visited) >= self::MAX_DEPTH) {
            return [];
        }

        $visited[] = $recipe->id;
        $composition = $this->compositions->at($recipe, $moment);
        $entries = [];
        /** @var array<int, list<array{0: Ingredient, 1: Quantity}>> $groups */
        $groups = [];

        foreach ($composition['lines'] as $line) {
            $quantity = $line['quantity']->scaled($numerator, $denominator);

            if ($line['alternative_group'] === null) {
                $entries[] = ['ingredient' => $line['ingredient'], 'quantity' => $quantity];
            } else {
                $groups[$line['alternative_group']][] = [$line['ingredient'], $quantity];
            }
        }

        foreach ($groups as $group => $options) {
            $entries[] = ['recipe' => $recipe, 'group' => $group, 'options' => $options, 'chosen' => $choices[$recipe->id.':'.$group] ?? null];
        }

        foreach ($composition['components'] as $component) {
            $inner = $component['recipe'];
            $innerBase = $this->compositions->at($inner, $moment)['base_servings'];
            $entries = [...$entries, ...$this->expand($inner, $numerator * $component['servings'], $denominator * $innerBase, $choices, $visited, $moment)];
        }

        return $entries;
    }
}
