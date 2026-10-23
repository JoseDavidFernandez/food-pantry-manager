<?php

namespace App\Actions\Menus;

use App\Actions\Inventory\StockEstimator;
use App\Actions\Recipes\RecipeExpansion;
use App\Enums\DishType;
use App\Enums\Meal;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\PlannedDish;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use App\Support\StockAllocation;
use App\Support\Week;
use Carbon\CarbonImmutable;

/**
 * The pending dishes of a household (reference time still to come), in the order they will be eaten (date,
 * lunch before dinner, position), each with what it needs (RN-36, RN-42). The stock is handed
 * out in that order: every dish takes its fixed lines and confirmed choices from what the
 * dishes before it left, then its proposals are decided with the rest. So the same stock is
 * never counted for two dishes, a later dish never changes an earlier proposal, and the menu
 * and every list see the same proposal for a dish. Read only: nothing is reserved (RN-10).
 *
 * @phpstan-import-type Line from RecipeExpansion
 */
class DishPlan
{
    public function __construct(
        private readonly RecipeExpansion $expansion,
        private readonly StockEstimator $estimator,
    ) {}

    /**
     * `stock` is the estimated stock (RN-44); `registered`, what the user registered.
     *
     * @return array{
     *     units: FoodUnitsMap,
     *     stock: array<string, Quantity>,
     *     registered: array<string, Quantity>,
     *     estimate: array{rows: array<string, mixed>, totals: array<string, Quantity>},
     *     stock_ingredients: array<int, true>,
     *     dishes: list<array{date: CarbonImmutable, dish: PlannedDish, lines: list<Line>}>,
     *     by_dish: array<int, list<Line>>
     * }
     */
    public function until(Household $household, CarbonImmutable $end): array
    {
        $units = FoodUnitsMap::forHousehold($household);
        [$registered, $stockIngredients] = $this->stock($household, $units);
        // What is probably at home now: registered stock minus the meals already past (RN-44).
        $estimate = $this->estimator->estimate($household, $units);
        $stock = $estimate['totals'];
        $allocation = new StockAllocation($stock);
        $dishes = [];
        $byDish = [];

        foreach ($this->pending($household, PeriodRequirements::today(), $end) as [$date, $dish]) {
            $lines = $this->lines($dish, $allocation, $units);
            $dishes[] = ['date' => $date, 'dish' => $dish, 'lines' => $lines];
            $byDish[$dish->id] = $lines;
        }

        return ['units' => $units, 'stock' => $stock, 'registered' => $registered, 'estimate' => $estimate, 'stock_ingredients' => $stockIngredients, 'dishes' => $dishes, 'by_dish' => $byDish];
    }

    /**
     * What one dish needs. Without an allocation (a past dish, for example) nothing is
     * considered to be at home for its proposals.
     *
     * @return list<Line>
     */
    public function lines(PlannedDish $dish, ?StockAllocation $allocation = null, ?FoodUnitsMap $units = null): array
    {
        $allocation ??= StockAllocation::none();
        $units ??= FoodUnitsMap::forHousehold($dish->menu->household);

        if ($dish->type === DishType::Recipe) {
            return $this->expansion->forDish($dish, $allocation, $units);
        }

        $lines = [];

        foreach ($dish->ingredients as $line) {
            if ($line->ingredient !== null) {
                $quantity = $line->toQuantity();
                [$key, $normalized] = $units->keyed($line->ingredient->id, $quantity);
                $allocation->take($key, $normalized);
                $lines[] = ['ingredient' => $line->ingredient, 'quantity' => $quantity, 'choice' => null];
            }
        }

        return $lines;
    }

    /**
     * Registered stock of every location combined per ingredient and compatible unit group, in the canonical
     * unit of each food (RN-40: two cabezas of garlic count as 20 dientes). Rows keep their units.
     *
     * @return array{0: array<string, Quantity>, 1: array<int, true>}
     */
    public function stock(Household $household, ?FoodUnitsMap $units = null): array
    {
        $units ??= FoodUnitsMap::forHousehold($household);
        $stock = [];
        $ingredients = [];

        foreach ($household->inventoryItems()->get() as $item) {
            /** @var InventoryItem $item */
            [$key, $quantity] = $units->keyed($item->ingredient_id, $item->toQuantity());
            $stock[$key] = isset($stock[$key]) ? $stock[$key]->plus($quantity) : $quantity;
            $ingredients[$item->ingredient_id] = true;
        }

        return [$stock, $ingredients];
    }

    /**
     * Planned dishes still pending (their reference time, RN-44, has not arrived yet) up to $end,
     * with their date, in the order they are eaten, skipping those marked «No comido» (RN-37).
     * From their reference time on, dishes count as eaten for the estimated stock instead.
     *
     * @return list<array{0: CarbonImmutable, 1: PlannedDish}>
     */
    public function pending(Household $household, CarbonImmutable $today, CarbonImmutable $end): array
    {
        if ($end->lessThan($today)) {
            return [];
        }

        $dishes = PlannedDish::query()
            ->whereNull('not_eaten_at')
            ->whereHas('menu', fn ($query) => $query
                ->where('household_id', $household->id)
                ->whereDate('week_start', '>=', Week::containing($today->format('Y-m-d'))->key())
                ->whereDate('week_start', '<=', $end->format('Y-m-d')))
            ->with(['menu.household', 'recipe.ingredients.ingredient', 'recipe.components.component', 'ingredients.ingredient'])
            ->get();

        $pending = [];

        foreach ($dishes as $dish) {
            $date = CarbonImmutable::parse($dish->menu->week_start->format('Y-m-d'), Week::timezone())->addDays($dish->day - 1);

            if ($dish->referenceTime()->greaterThan(CarbonImmutable::now()) && $date->lessThanOrEqualTo($end)) {
                $pending[] = [$date, $dish];
            }
        }

        $meals = array_flip(array_map(fn (Meal $meal) => $meal->value, Meal::cases()));
        usort($pending, fn (array $a, array $b) => [$a[0]->format('Y-m-d'), $meals[$a[1]->meal->value], $a[1]->position, $a[1]->id]
            <=> [$b[0]->format('Y-m-d'), $meals[$b[1]->meal->value], $b[1]->position, $b[1]->id]);

        return $pending;
    }

    /**
     * Whether a dish uses an option that is only a proposal (or a choice that no longer exists).
     *
     * @param  list<Line>  $lines
     */
    public static function hasProposal(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['choice'] !== null && ! $line['choice']['confirmed']) {
                return true;
            }
        }

        return false;
    }
}
