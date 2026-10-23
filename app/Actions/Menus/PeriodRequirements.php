<?php

namespace App\Actions\Menus;

use App\Actions\Inventory\FoodRestock;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\PlannedDish;
use App\Support\Quantity;
use App\Support\Week;
use Carbon\CarbonImmutable;

/**
 * What to buy for a purchase period chosen by the user (RN-36), from the planned dishes of
 * any week and the physical stock. Read only: planning never deducts stock (RN-10).
 *
 * Per ingredient and compatible unit group:
 * - stock: what is physically at home;
 * - committed: what the pending dishes need (from today on, not marked «No comido», RN-37);
 *   past dishes no longer count;
 * - available: stock minus committed, never below zero;
 * - to buy: what the dishes of the period need beyond the stock left after the pending
 *   dishes before the period (the stock goes to the earliest dishes first), rounded up to whole
 *   packs of the food's purchase unit (RN-41) or, without one, for indivisible units (RN-23).
 *   Stock in a non-convertible unit is not subtracted (RN-22); a food's own conversions make
 *   more units convertible (RN-40).
 */
class PeriodRequirements
{
    public const MAX_DAYS = 92;

    public function __construct(
        private readonly DishPlan $plan,
        private readonly FoodRestock $restock,
    ) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(Week::timezone())->startOfDay();
    }

    public static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'), Week::timezone());
    }

    /**
     * @return list<array{
     *     key: string, ingredient: Ingredient, need: Quantity, recipes: Quantity|null, restock: Quantity|null,
     *     restock_mode: string|null, have: Quantity|null, registered: Quantity|null,
     *     committed: Quantity|null, available: Quantity|null, to_buy: Quantity|null,
     *     before: Quantity|null, missing: Quantity|null, pack: string|null, review: bool, dishes: list<string>
     * }>
     */
    public function calculate(Household $household, CarbonImmutable $start, CarbonImmutable $end): array
    {
        // Whole days in Europe/Madrid, whatever the timezone of the dates received (CA-17).
        $start = self::day($start);
        $end = self::day($end);
        $today = self::today();
        $from = $start->greaterThan($today) ? $start : $today;

        $before = [];
        $period = [];
        $committed = [];
        // Dishes in order, each with its share of the stock for its proposals (RN-42).
        $plan = $this->plan->until($household, $end);
        $units = $plan['units'];
        $stock = $plan['stock'];
        $stockGroups = $plan['stock_ingredients'];
        $registered = $plan['registered'];

        foreach ($plan['dishes'] as ['date' => $date, 'dish' => $dish, 'lines' => $needs]) {
            foreach ($needs as ['ingredient' => $ingredient, 'quantity' => $quantity]) {
                [$key, $quantity] = $units->keyed($ingredient->id, $quantity);
                $this->add($committed, $key, $ingredient, $quantity, $dish);

                if ($date->lessThan($from)) {
                    $this->add($before, $key, $ingredient, $quantity, $dish);
                } else {
                    $this->add($period, $key, $ingredient, $quantity, $dish);
                }
            }
        }

        // RN-47: what must stay at home of staple foods (a minimum, or a single restock after
        // «Se acabó») is added to what the period needs, on the same line as the recipes.
        $restocks = [];

        foreach ($this->restock->forHousehold($household) as [$ingredient, $quantity, $mode]) {
            [$key, $quantity] = $units->keyed($ingredient->id, $quantity);
            $restocks[$key] = ['ingredient' => $ingredient, 'quantity' => $quantity, 'mode' => $mode];
        }

        $lines = [];

        foreach (array_unique([...array_keys($period), ...array_keys($restocks)]) as $key) {
            $recipes = $period[$key]['quantity'] ?? null;
            $restock = $restocks[$key]['quantity'] ?? null;
            /** @var Ingredient $ingredient */
            $ingredient = $period[$key]['ingredient'] ?? $restocks[$key]['ingredient'];
            /** @var Quantity $total */
            $total = $recipes !== null && $restock !== null ? $recipes->plus($restock) : ($recipes ?? $restock);
            $have = $stock[$key] ?? null;
            $left = $have === null ? null : (isset($before[$key]) ? $have->minus($before[$key]['quantity']) : $have);
            $toBuy = $left === null ? $total : $total->minus($left);
            $food = $units->for($ingredient->id);
            $packs = $toBuy === null ? null : $food->forPurchase($toBuy);
            $committedHere = $committed[$key]['quantity'] ?? null;

            $lines[] = [
                'key' => $key,
                'ingredient' => $ingredient,
                // Everything to cover in the period: the recipes plus what must stay at home.
                'need' => $total,
                'recipes' => $recipes,
                'restock' => $restock,
                'restock_mode' => $restocks[$key]['mode'] ?? null,
                // Estimated (RN-44); what is registered, to show both when they differ.
                'have' => $have,
                'registered' => $registered[$key] ?? null,
                'committed' => $committedHere,
                // Pending dishes before the period that take from the same stock (presentation).
                'before' => $before[$key]['quantity'] ?? null,
                'available' => $committedHere === null ? $have : $have?->minus($committedHere),
                'to_buy' => $packs ?? $toBuy?->roundedUpForPurchase(),
                'missing' => $toBuy,
                'pack' => $packs === null ? null : $food->packContent(),
                // Only stock registered in another, non-convertible unit needs a review (RN-22);
                // an estimate down to zero simply means it has to be bought.
                'review' => ! isset($registered[$key]) && isset($stockGroups[$ingredient->id]),
                'dishes' => array_values(array_unique($period[$key]['dishes'] ?? [])),
            ];
        }

        usort($lines, fn (array $a, array $b) => strcoll($a['ingredient']->normalized_name, $b['ingredient']->normalized_name));

        return $lines;
    }

    /**
     * What the period counts, to explain the list (point 2, 2026-10-22): how many dishes of the
     * period count, how many dishes of this week are on days already past (they are kept as
     * history and never generate purchases, RN-36), and how many dishes counted use an option of
     * alternative ingredients that is only a proposal (RN-42).
     *
     * @return array{dishes: int, past_this_week: int, proposals: int}
     */
    public function coverage(Household $household, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $today = self::today();
        $from = self::day($start)->greaterThan($today) ? self::day($start) : $today;
        $counted = array_filter(
            $this->plan->until($household, self::day($end))['dishes'],
            fn (array $entry) => $entry['date']->greaterThanOrEqualTo($from),
        );

        $week = Week::current();
        $past = PlannedDish::query()
            ->whereNull('not_eaten_at')
            ->where('day', '<', $today->isoWeekday())
            ->whereHas('menu', fn ($query) => $query->where('household_id', $household->id)->whereDate('week_start', $week->key()))
            ->count();

        $proposals = count(array_filter($counted, fn (array $entry) => DishPlan::hasProposal($entry['lines'])));

        return ['dishes' => count($counted), 'past_this_week' => $past, 'proposals' => $proposals];
    }

    /**
     * @param  array<string, array{ingredient: Ingredient, quantity: Quantity, dishes: list<string>}>  $needs
     */
    private function add(array &$needs, string $key, Ingredient $ingredient, Quantity $quantity, PlannedDish $dish): void
    {
        if (isset($needs[$key])) {
            $needs[$key]['quantity'] = $needs[$key]['quantity']->plus($quantity);
            $needs[$key]['dishes'][] = $dish->displayName();
        } else {
            $needs[$key] = ['ingredient' => $ingredient, 'quantity' => $quantity, 'dishes' => [$dish->displayName()]];
        }
    }
}
