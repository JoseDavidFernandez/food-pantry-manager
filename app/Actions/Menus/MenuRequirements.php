<?php

namespace App\Actions\Menus;

use App\Enums\DishType;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\PlannedDish;
use App\Support\Quantity;
use App\Support\Week;
use Carbon\CarbonImmutable;

/**
 * Read-only comparison of what a weekly menu needs against the household stock (RN-21, RN-22).
 * Recipes are scaled by planned servings (RN-13); compatible units are combined (RN-06);
 * nothing is reserved or deducted (RN-10).
 */
class MenuRequirements
{
    public const ENOUGH = 'enough';

    public const SHORT = 'short';

    public const MISSING = 'missing';

    public const REVIEW = 'review';

    public function __construct(private readonly DishPlan $plan) {}

    /**
     * @return array{
     *     lines: list<array{ingredient: string, need: string, have: string|null, missing: string|null, status: string, dishes: list<string>}>,
     *     incomplete: list<array{slot: string, dish: string, reason: string}>
     * }
     */
    public function forMenu(Household $household, Week $week, ?Menu $menu): array
    {
        if ($menu === null) {
            return ['lines' => [], 'incomplete' => []];
        }

        $result = $this->calculate($household, $week, $menu);
        $order = [self::SHORT => 0, self::MISSING => 0, self::REVIEW => 1, self::ENOUGH => 2];

        $lines = array_map(fn (array $line) => [
            'ingredient' => $line['ingredient']->name,
            'need' => $line['need']->readable(),
            'have' => $line['have']?->readable(),
            // Shown as «≈ previsto · registrado» when they differ (RN-44).
            'registered' => $line['registered'] !== null && ($line['have'] === null || $line['have']->compareTo($line['registered']) !== 0) ? $line['registered']->readable() : null,
            'missing' => $line['missing']?->readable(),
            'status' => $line['status'],
            'dishes' => $line['dishes'],
        ], $result['lines']);

        usort($lines, fn (array $a, array $b) => [$order[$a['status']], $a['ingredient']] <=> [$order[$b['status']], $b['ingredient']]);

        return ['lines' => $lines, 'incomplete' => $result['incomplete']];
    }

    /**
     * Raw calculation shared by the menu view and the shopping list (RN-21, RN-22):
     * scaled needs per ingredient and compatible group, compatible stock of both locations,
     * what is missing (never negative) and whether only incompatible stock exists.
     *
     * @return array{
     *     lines: list<array{key: string, ingredient: Ingredient, need: Quantity, have: Quantity|null, registered: Quantity|null, missing: Quantity|null, status: string, dishes: list<string>}>,
     *     incomplete: list<array{slot: string, dish: string, reason: string}>
     * }
     */
    public function calculate(Household $household, Week $week, Menu $menu): array
    {
        $menu->loadMissing(['dishes.recipe.ingredients.ingredient', 'dishes.ingredients.ingredient', 'dishes.choices']);
        $dayLabels = array_column($week->days(), 'label', 'day');

        /** @var array<string, array{ingredient: Ingredient, quantity: Quantity, dishes: list<string>}> $needs */
        $needs = [];
        $incomplete = [];
        // Each food's own conversions (RN-40): needs and stock are compared in its canonical unit.
        // Pending dishes take their share of the stock in order, from today to the end of this
        // week (RN-42); a past dish of the week is shown with proposals made without stock.
        $plan = $this->plan->until($household, CarbonImmutable::parse($week->key(), Week::timezone())->addDays(6));
        $units = $plan['units'];
        $stock = $plan['stock'];
        $stockGroups = $plan['stock_ingredients'];
        $registered = $plan['registered'];

        // RN-37: a dish marked as «No comido» needs nothing. RN-44: a dish whose reference time
        // has passed is already counted in the estimated stock, so it needs nothing either:
        // only pending dishes are compared with what is (probably) at home, never twice.
        foreach ($menu->dishes->reject(fn (PlannedDish $dish) => $dish->isNotEaten() || ! isset($plan['by_dish'][$dish->id]))->sortBy(['day', 'meal']) as $dish) {
            $slot = ($dayLabels[$dish->day] ?? '').' · '.$dish->meal->label();

            foreach ($plan['by_dish'][$dish->id] as ['ingredient' => $ingredient, 'quantity' => $quantity]) {
                [$key, $quantity] = $units->keyed($ingredient->id, $quantity);

                if (isset($needs[$key])) {
                    $needs[$key]['quantity'] = $needs[$key]['quantity']->plus($quantity);
                    $needs[$key]['dishes'][] = $dish->displayName();
                } else {
                    $needs[$key] = ['ingredient' => $ingredient, 'quantity' => $quantity, 'dishes' => [$dish->displayName()]];
                }
            }

            $reason = $this->incompleteReason($dish);

            if ($reason !== null) {
                $incomplete[] = ['slot' => $slot, 'dish' => $dish->displayName(), 'reason' => $reason];
            }
        }

        $lines = [];

        foreach ($needs as $key => $need) {
            $have = $stock[$key] ?? null;

            if ($have !== null) {
                $missing = $need['quantity']->minus($have);
                $status = $missing === null ? self::ENOUGH : self::SHORT;
            } else {
                $missing = $need['quantity'];
                // Review only for stock registered in a non-convertible unit (RN-22), not for an
                // estimate that reached zero (RN-44).
                $status = ! isset($registered[$key]) && isset($stockGroups[$need['ingredient']->id]) ? self::REVIEW : self::MISSING;
            }

            $lines[] = [
                'key' => $key,
                'ingredient' => $need['ingredient'],
                'need' => $need['quantity'],
                'have' => $have,
                'registered' => $registered[$key] ?? null,
                'missing' => $missing,
                'status' => $status,
                'dishes' => array_values(array_unique($need['dishes'])),
            ];
        }

        return ['lines' => $lines, 'incomplete' => $incomplete];
    }

    private function incompleteReason(PlannedDish $dish): ?string
    {
        if ($dish->type === DishType::Recipe) {
            return $dish->recipe?->ingredients->isEmpty() && $dish->recipe->components()->doesntExist() ? 'La receta no tiene ingredientes.' : null;
        }

        return $dish->ingredients->isEmpty() ? 'El plato no tiene ingredientes.' : null;
    }
}
