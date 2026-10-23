<?php

namespace App\Actions\Inventory;

use App\Actions\Recipes\RecipeExpansion;
use App\Enums\DishType;
use App\Enums\StorageLocation;
use App\Enums\UnitGroup;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\PlannedDish;
use App\Models\StockEvent;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use App\Support\StockAllocation;
use App\Support\Week;
use Carbon\CarbonImmutable;

/**
 * Estimated stock (RN-44): what is probably at home now, from what the user registered and the
 * meals planned since then, without anybody confirming meals. Read only: the registered rows in
 * `inventory_items` never change by themselves.
 *
 * Each row (food, location and compatible group) has its own estimate, in the canonical unit of
 * its food. The stock events and the meals already past (reference time reached, not marked
 * «No comido») are replayed in time order; at the same moment a meal goes before an event:
 * - add: the row grows by the quantity;
 * - count: the row becomes exactly the quantity stated (a count to zero removes it), and what
 *   was consumed from it before no longer matters;
 * - move: the row and its estimate go to the other location (adding up if a row is there); if
 *   the quantity changed while moving, the moved part is counted;
 * - meal: each ingredient is taken from the rows of that food in the order fridge, pantry,
 *   freezer, each down to zero at most; what does not fit is a shortfall and is dropped.
 *
 * A past meal uses its recipes as they were at its reference time (RN-45) and, for each group of
 * alternatives, the option chosen or else the one the estimate at that moment proposes (RN-42).
 *
 * @phpstan-type RowEstimate array{ingredient_id: int, location: StorageLocation, group: UnitGroup, key: string, estimated: Quantity|null, consumed: list<array{dish: string, at: CarbonImmutable, quantity: Quantity}>}
 */
class StockEstimator
{
    public function __construct(private readonly RecipeExpansion $expansion) {}

    /**
     * @return array{rows: array<string, RowEstimate>, totals: array<string, Quantity>}
     */
    public function estimate(Household $household, FoodUnitsMap $units, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $events = StockEvent::query()->where('household_id', $household->id)->orderBy('occurred_at')->orderBy('id')->get();

        if ($events->isEmpty()) {
            return ['rows' => [], 'totals' => []];
        }

        /** @var array<string, RowEstimate> $rows */
        $rows = [];
        $timeline = [];

        foreach ($events as $event) {
            /** @var StockEvent $event */
            $timeline[] = [$event->occurred_at, 1, 0, $event->id, $event];
        }

        foreach ($this->pastDishes($household, $events->first()->occurred_at, $now) as $dish) {
            $timeline[] = [$dish->referenceTime(), 0, $dish->position, $dish->id, $dish];
        }

        // By moment; at the same moment, meals before events (a count right at the reference
        // time already reflects that meal), then by position and id.
        usort($timeline, fn (array $a, array $b) => [$a[0]->getTimestamp(), (int) $a[0]->format('u'), $a[1], $a[2], $a[3]]
            <=> [$b[0]->getTimestamp(), (int) $b[0]->format('u'), $b[1], $b[2], $b[3]]);

        foreach ($timeline as [$moment, , , , $entry]) {
            if ($entry instanceof StockEvent) {
                $this->apply($rows, $entry, $units);
            } else {
                $this->consume($rows, $entry, $moment, $units);
            }
        }

        $totals = [];

        foreach ($rows as $row) {
            if ($row['estimated'] !== null) {
                $totals[$row['key']] = isset($totals[$row['key']]) ? $totals[$row['key']]->plus($row['estimated']) : $row['estimated'];
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    public static function rowKey(int $ingredientId, StorageLocation $location, UnitGroup $group): string
    {
        return $ingredientId.'|'.$location->value.'|'.$group->value;
    }

    /**
     * @param  array<string, RowEstimate>  $rows
     */
    private function apply(array &$rows, StockEvent $event, FoodUnitsMap $units): void
    {
        $key = self::rowKey($event->ingredient_id, $event->location, $event->unit_group);
        $quantity = $event->toQuantity();
        $canonical = $quantity === null ? null : $units->keyed($event->ingredient_id, $quantity)[1];

        if ($event->kind === StockEvent::ADD) {
            if ($canonical !== null) {
                $row = $rows[$key] ?? $this->emptyRow($event->ingredient_id, $event->location, $event->unit_group, $units);
                $row['estimated'] = $row['estimated'] === null ? $canonical : $row['estimated']->plus($canonical);
                $rows[$key] = $row;
            }

            return;
        }

        if ($event->kind === StockEvent::COUNT) {
            if ($canonical === null) {
                unset($rows[$key]);
            } else {
                $rows[$key] = [...$this->emptyRow($event->ingredient_id, $event->location, $event->unit_group, $units), 'estimated' => $canonical];
            }

            return;
        }

        // Move: the row goes to the other location; a quantity given is the moved part, counted.
        $moved = $rows[$key] ?? $this->emptyRow($event->ingredient_id, $event->location, $event->unit_group, $units);
        unset($rows[$key]);

        if ($event->to_location === null) {
            return;
        }

        if ($event->quantity !== null) {
            $moved['estimated'] = $canonical;
            $moved['consumed'] = [];
        }

        $target = self::rowKey($event->ingredient_id, $event->to_location, $event->unit_group);
        $row = $rows[$target] ?? $this->emptyRow($event->ingredient_id, $event->to_location, $event->unit_group, $units);
        $row['estimated'] = match (true) {
            $row['estimated'] === null => $moved['estimated'],
            $moved['estimated'] === null => $row['estimated'],
            default => $row['estimated']->plus($moved['estimated']),
        };
        $row['consumed'] = [...$row['consumed'], ...$moved['consumed']];
        $rows[$target] = $row;
    }

    /**
     * @param  array<string, RowEstimate>  $rows
     */
    private function consume(array &$rows, PlannedDish $dish, CarbonImmutable $moment, FoodUnitsMap $units): void
    {
        // The estimate at that moment proposes the option of its alternatives (RN-42).
        $available = [];

        foreach ($rows as $row) {
            if ($row['estimated'] !== null) {
                $available[$row['key']] = isset($available[$row['key']]) ? $available[$row['key']]->plus($row['estimated']) : $row['estimated'];
            }
        }

        foreach ($this->dishLines($dish, new StockAllocation($available), $units, $moment) as [$ingredient, $quantity]) {
            [$key, $needed] = $units->keyed($ingredient->id, $quantity);
            $candidates = array_keys(array_filter($rows, fn (array $row) => $row['key'] === $key && $row['estimated'] !== null));
            usort($candidates, fn (string $a, string $b) => [$rows[$a]['location']->consumptionOrder(), $a] <=> [$rows[$b]['location']->consumptionOrder(), $b]);

            foreach ($candidates as $rowKey) {
                $row = $rows[$rowKey];

                if ($needed === null) {
                    break;
                }

                if ($row['estimated'] === null) {
                    continue;
                }

                $taken = $row['estimated']->compareTo($needed) <= 0 ? $row['estimated'] : $needed;
                $row['estimated'] = $row['estimated']->minus($taken);
                $row['consumed'][] = ['dish' => $dish->displayName(), 'at' => $moment, 'quantity' => $taken];
                $rows[$rowKey] = $row;
                // What does not fit in any row is a shortfall: never below zero.
                $needed = $needed->minus($taken);
            }
        }
    }

    /**
     * @return list<array{0: Ingredient, 1: Quantity}>
     */
    private function dishLines(PlannedDish $dish, StockAllocation $allocation, FoodUnitsMap $units, CarbonImmutable $moment): array
    {
        if ($dish->type === DishType::Recipe) {
            return array_map(
                fn (array $line) => [$line['ingredient'], $line['quantity']],
                $this->expansion->forDish($dish, $allocation, $units, $moment),
            );
        }

        $lines = [];

        foreach ($dish->ingredients as $line) {
            if ($line->ingredient !== null) {
                $lines[] = [$line->ingredient, $line->toQuantity()];
            }
        }

        return $lines;
    }

    /**
     * Dishes whose reference time is between the first stock event and now, not «No comido».
     *
     * @return list<PlannedDish>
     */
    private function pastDishes(Household $household, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $dishes = PlannedDish::query()
            ->whereNull('not_eaten_at')
            ->whereHas('menu', fn ($query) => $query
                ->where('household_id', $household->id)
                ->whereDate('week_start', '>=', Week::containing($from->timezone(Week::timezone())->format('Y-m-d'))->key())
                ->whereDate('week_start', '<=', $now->timezone(Week::timezone())->format('Y-m-d')))
            ->with(['menu.household', 'recipe', 'ingredients.ingredient', 'choices'])
            ->get()
            ->filter(fn (PlannedDish $dish) => $dish->referenceTime()->lessThanOrEqualTo($now) && $dish->referenceTime()->greaterThanOrEqualTo($from))
            ->all();

        return array_values($dishes);
    }

    /**
     * @return RowEstimate
     */
    private function emptyRow(int $ingredientId, StorageLocation $location, UnitGroup $group, FoodUnitsMap $units): array
    {
        $canonical = $units->for($ingredientId)->canonicalGroup($group);

        return [
            'ingredient_id' => $ingredientId,
            'location' => $location,
            'group' => $group,
            'key' => $ingredientId.':'.$canonical->value,
            'estimated' => null,
            'consumed' => [],
        ];
    }
}
