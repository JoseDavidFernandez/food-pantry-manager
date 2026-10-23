<?php

use App\Actions\Inventory\StockEstimator;
use App\Models\Ingredient;
use App\Models\PlannedDish;
use App\Support\FoodUnitsMap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * The id of a planned dish by its place: week, day, meal and order within the slot (RN-16).
 *
 * @param  array{week: string, day: int|string, meal: string}  $slot
 */
function dishIn(array $slot, int $index = 0): int
{
    return (int) PlannedDish::query()
        ->where('day', $slot['day'])
        ->where('meal', $slot['meal'])
        ->whereHas('menu', fn ($query) => $query
            ->whereDate('week_start', $slot['week'])
            ->when(auth()->user(), fn ($query, $user) => $query->where('household_id', $user->currentHousehold()->id)))
        ->orderBy('position')->orderBy('id')
        ->skip($index)
        ->value('id');
}

/**
 * Sets what a slot holds, as planning did before slots held several dishes: changes its first
 * dish if there is one, or adds it. Tests of several dishes per slot add them explicitly.
 *
 * @param  array{week: string, day: int|string, meal: string}  $slot
 * @param  array<string, mixed>  $payload
 */
function planInSlot(array $slot, array $payload): TestResponse
{
    $id = dishIn($slot);

    return $id > 0
        ? test()->put(route('menu.dishes.update', $id), $payload)
        : test()->post(route('menu.dishes.store', $slot), $payload);
}

/**
 * Moves the clock to a moment in Europe/Madrid (reference times of meals, RN-44).
 */
function atMadrid(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'Europe/Madrid'));
}

/**
 * The estimated stock of a food (RN-44): its total in the canonical unit, and per location.
 *
 * @return array{total: string, rows: array<string, string>}
 */
function estimateOf(Ingredient $ingredient): array
{
    $household = $ingredient->household;
    $estimate = app(StockEstimator::class)->estimate($household, FoodUnitsMap::forHousehold($household));
    $rows = [];
    $total = '0';

    foreach ($estimate['rows'] as $row) {
        if ($row['ingredient_id'] === $ingredient->id) {
            $amount = $row['estimated']?->formattedAmount() ?? '0';
            $rows[$row['location']->value] = $amount;
            $total = bcadd($total, $row['estimated']?->amount ?? '0', 3);
        }
    }

    ksort($rows);

    return ['total' => plainAmount($total), 'rows' => $rows];
}

/**
 * What is registered for a food (rows of `inventory_items`), as a plain total.
 */
function registeredOf(Ingredient $ingredient): string
{
    $total = (string) $ingredient->inventoryItems()->sum('quantity');

    return plainAmount(bcadd(is_numeric($total) ? $total : '0', '0', 3));
}

/**
 * «18», «0», «2,5»: an amount without superfluous zeros.
 *
 * @param  numeric-string  $amount
 */
function plainAmount(string $amount): string
{
    $plain = rtrim(rtrim($amount, '0'), '.');

    return str_replace('.', ',', $plain === '' ? '0' : $plain);
}
