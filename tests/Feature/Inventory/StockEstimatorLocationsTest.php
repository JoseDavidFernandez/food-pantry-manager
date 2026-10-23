<?php

use App\Actions\Inventory\StockEstimator;
use App\Actions\Inventory\StockManager;
use App\Actions\Menus\MenuPlanner;
use App\Enums\DishType;
use App\Enums\Meal;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\StockEvent;
use App\Models\User;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * CA-36, RN-44, several locations (Phase A): each row has its own estimate; meals take from the
 * fridge first, then the pantry, then the freezer; a count only touches its row; a move takes
 * the estimate with it. Start of every case: fridge 6 and pantry 12 on Monday 19 Oct 2026 at
 * 08:00, a tortilla of 4 eggs for lunch on Monday, Tuesday and Wednesday, «now» Thursday 10:00:
 * fridge 0, pantry 6 (estimate 6, registered 18).
 */
beforeEach(function () {
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
    $this->huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
});

function startTwoLocations(): void
{
    $tortilla = Recipe::factory()->for(test()->household)->withIngredients([[test()->huevos, Quantity::of('4', Unit::Piece)]])
        ->create(['name' => 'Tortilla', 'base_servings' => 1]);

    foreach ([1, 2, 3] as $day) {
        test()->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => $day, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => $tortilla->id, 'servings' => 1]);
    }

    foreach (['fridge' => '6', 'pantry' => '12'] as $location => $quantity) {
        test()->post(route('inventory.store'), ['ingredient' => 'Huevos', 'quantity' => $quantity, 'unit' => 'piece', 'location' => $location])->assertSessionHasNoErrors();
    }

    atMadrid('2026-10-22 10:00');
}

function eggRow(string $location): InventoryItem
{
    return InventoryItem::query()->where('ingredient_id', test()->huevos->id)->where('location', $location)->sole();
}

function countRow(string $location, string $quantity): void
{
    test()->patch(route('inventory.adjust', eggRow($location)), ['quantity' => $quantity])->assertSessionHasNoErrors();
}

/**
 * Moving from the edit dialog, which shows the estimate when there is one: without $quantity
 * the shown value is sent back (a pure move); with it, the moved part is counted.
 */
function moveRow(string $from, string $to, ?string $quantity = null): void
{
    $row = eggRow($from);
    $shown = estimateOf(test()->huevos)['rows'][$from] ?? $row->toQuantity()->formattedAmount();
    test()->put(route('inventory.update', $row), ['quantity' => $quantity ?? $shown, 'unit' => 'piece', 'location' => $to])
        ->assertSessionHasNoErrors();
}

/**
 * A move of the whole row as the domain sees it (the dialog cannot send an estimate of 0).
 */
function moveWholeRow(string $from, string $to): void
{
    $row = eggRow($from);
    app(StockManager::class)->update($row, $row->toQuantity(), StorageLocation::from($to), null);
}

function deleteRow(string $location): void
{
    test()->delete(route('inventory.destroy', eggRow($location)))->assertSessionHasNoErrors();
}

test('L0: the start of every case', function () {
    startTwoLocations();

    expect(estimateOf($this->huevos))->toBe(['total' => '6', 'rows' => ['fridge' => '0', 'pantry' => '6']])
        ->and(registeredOf($this->huevos))->toBe('18');
});

dataset('location cases', [
    'L1 count the fridge to 4' => [fn () => countRow('fridge', '4'), '10', ['fridge' => '4', 'pantry' => '6'], '16'],
    'L2 delete the pantry' => [fn () => deleteRow('pantry'), '0', ['fridge' => '0'], '6'],
    'L3 count the fridge to 8' => [fn () => countRow('fridge', '8'), '14', ['fridge' => '8', 'pantry' => '6'], '20'],
    'L4 fridge to 2, then pantry to 3' => [function () {
        countRow('fridge', '2');
        countRow('pantry', '3');
    }, '5', ['fridge' => '2', 'pantry' => '3'], '5'],
    'L4b pantry to 3, then fridge to 2' => [function () {
        countRow('pantry', '3');
        countRow('fridge', '2');
    }, '5', ['fridge' => '2', 'pantry' => '3'], '5'],
    'L4c both counted without changes' => [function () {
        countRow('fridge', '6');
        countRow('pantry', '12');
    }, '18', ['fridge' => '6', 'pantry' => '12'], '18'],
    'L5 move the pantry to the freezer' => [fn () => moveRow('pantry', 'freezer'), '6', ['freezer' => '6', 'fridge' => '0'], '18'],
    'L6 move the fridge into the pantry (merge)' => [fn () => moveWholeRow('fridge', 'pantry'), '6', ['pantry' => '6'], '18'],
    'L7 move the fridge into the pantry stating 5' => [fn () => moveRow('fridge', 'pantry', '5'), '11', ['pantry' => '11'], '17'],
    'L8 delete the pantry row' => [fn () => deleteRow('pantry'), '0', ['fridge' => '0'], '6'],
    'L9 delete the fridge row' => [fn () => deleteRow('fridge'), '6', ['pantry' => '6'], '12'],
    'L10 delete both rows' => [function () {
        deleteRow('fridge');
        deleteRow('pantry');
    }, '0', [], '0'],
    'L11 L1, then 12 bought into the freezer' => [function () {
        countRow('fridge', '4');
        test()->post(route('inventory.store'), ['ingredient' => 'Huevos', 'quantity' => '12', 'unit' => 'piece', 'location' => 'freezer']);
    }, '22', ['freezer' => '12', 'fridge' => '4', 'pantry' => '6'], '28'],
    'L16 the fridge counted to 4 twice' => [function () {
        countRow('fridge', '4');
        countRow('fridge', '4');
    }, '10', ['fridge' => '4', 'pantry' => '6'], '16'],
]);

test('several locations', function (Closure $operation, string $total, array $rows, string $registered) {
    startTwoLocations();
    $operation();

    expect(estimateOf($this->huevos))->toBe(['total' => $total, 'rows' => $rows])
        ->and(registeredOf($this->huevos))->toBe($registered);
})->with('location cases');

test('L12: a later meal keeps taking from the fridge first', function () {
    startTwoLocations();
    countRow('fridge', '4');
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch']), [
        'type' => 'adhoc', 'name' => 'Revuelto', 'servings' => 1,
        'ingredients' => [['ingredient' => 'Huevos', 'quantity' => '4', 'unit' => 'piece']],
    ]);
    atMadrid('2026-10-22 16:00');

    expect(estimateOf($this->huevos))->toBe(['total' => '6', 'rows' => ['fridge' => '0', 'pantry' => '6']])
        ->and(registeredOf($this->huevos))->toBe('16');
});

test('L13: «No comido» on a meal before a count of that row changes nothing for that row', function () {
    startTwoLocations();
    countRow('fridge', '4');
    // Monday gives 4 back: the fridge was counted after it, so it goes to the pantry estimate.
    $this->patch(route('menu.dishes.eaten', dishIn(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'])), ['eaten' => '0']);

    expect(estimateOf($this->huevos))->toBe(['total' => '14', 'rows' => ['fridge' => '4', 'pantry' => '10']]);
});

test('L14: groups that do not convert are separate, and a count of one leaves the other alone', function () {
    startTwoLocations();
    $this->post(route('inventory.store'), ['ingredient' => 'Huevos', 'quantity' => '1', 'unit' => 'carton', 'location' => 'pantry'])->assertSessionHasNoErrors();
    countRow('fridge', '2');

    $estimate = app(StockEstimator::class)->estimate($this->household, FoodUnitsMap::forHousehold($this->household));
    expect($estimate['totals'])->toHaveKeys([$this->huevos->id.':piece', $this->huevos->id.':carton'])
        ->and($estimate['totals'][$this->huevos->id.':piece']->format())->toBe('8 unidades')
        ->and($estimate['totals'][$this->huevos->id.':carton']->format())->toBe('1 cartón');
});

test('L15: with a conversion, bags and grams are one food in the canonical unit', function () {
    atMadrid('2026-10-19 08:00');
    $patatas = Ingredient::factory()->for($this->household)->named('Patatas')->create();
    $patatas->conversions()->create(['unit' => 'bag', 'factor' => '500', 'to_unit' => 'g']);
    $this->post(route('inventory.store'), ['ingredient' => 'Patatas', 'quantity' => '2', 'unit' => 'bag', 'location' => 'pantry']);
    $this->post(route('inventory.store'), ['ingredient' => 'Patatas', 'quantity' => '300', 'unit' => 'g', 'location' => 'fridge']);
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch']), [
        'type' => 'adhoc', 'name' => 'Patatas fritas', 'servings' => 2,
        'ingredients' => [['ingredient' => 'Patatas', 'quantity' => '600', 'unit' => 'g']],
    ]);
    atMadrid('2026-10-20 10:00');

    // 600 g: 300 from the fridge, 300 from the two bags (1000 g).
    expect(estimateOf($patatas))->toBe(['total' => '700', 'rows' => ['fridge' => '0', 'pantry' => '700']]);
});

test('moves and counts are recorded as stock events; a move alone is never a count', function () {
    startTwoLocations();
    moveRow('pantry', 'freezer');
    countRow('freezer', '10');
    deleteRow('fridge');

    expect(StockEvent::query()->where('ingredient_id', $this->huevos->id)->orderBy('id')->pluck('kind')->all())
        ->toBe(['add', 'add', 'move', 'count', 'count']);
});

/**
 * Seeded random sequences against a plain model of the rule (one row per location, integers):
 * the properties of the specification must hold at every step.
 */
test('properties hold on random sequences of meals, purchases, counts, moves and deletions', function () {
    $stock = app(StockManager::class);
    $planner = app(MenuPlanner::class);
    $locations = ['fridge', 'pantry', 'freezer'];

    foreach (range(1, 25) as $seed) {
        // Its own generator: reseeding mt_rand would also reseed the factories' fake data.
        $rng = new Randomizer(new Mt19937($seed));
        // The ingredient factory draws from ten unique names: start each sequence afresh.
        fake()->unique(true);
        $household = User::factory()->withHousehold()->create()->currentHousehold();
        $eggs = Ingredient::factory()->for($household)->named('Huevos')->create();
        /** @var array<string, array{r: int, e: int}> $model */
        $model = [];
        $day = CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Madrid');

        foreach (range(1, $rng->getInt(3, 9)) as $step) {
            $this->travelTo($day->setTime(10, 0));
            $location = $locations[$rng->getInt(0, 2)];
            $row = InventoryItem::query()->where('ingredient_id', $eggs->id)->where('location', $location)->first();

            switch ($rng->getInt(0, 3)) {
                case 0:
                    $q = $rng->getInt(1, 12);
                    $stock->add($household, $eggs, StorageLocation::from($location), Quantity::of((string) $q, Unit::Piece));
                    $model[$location] = ['r' => ($model[$location]['r'] ?? 0) + $q, 'e' => ($model[$location]['e'] ?? 0) + $q];
                    break;
                case 1:
                    if ($row !== null) {
                        $v = $rng->getInt(0, 15);
                        $v === 0 ? $stock->remove($row) : $stock->update($row, Quantity::of((string) $v, Unit::Piece), $row->location, null, counted: true);
                        if ($v === 0) {
                            unset($model[$location]);
                        } else {
                            $model[$location] = ['r' => $v, 'e' => $v];
                        }
                    }
                    break;
                case 2:
                    if ($row !== null) {
                        $to = $locations[(array_search($location, $locations, true) + $rng->getInt(1, 2)) % 3];
                        $stock->update($row, $row->toQuantity(), StorageLocation::from($to), null);
                        $moved = $model[$location];
                        unset($model[$location]);
                        $model[$to] = ['r' => ($model[$to]['r'] ?? 0) + $moved['r'], 'e' => ($model[$to]['e'] ?? 0) + $moved['e']];
                    }
                    break;
                default:
                    $c = $rng->getInt(1, 8);
                    $menu = $planner->findOrCreate($household, Week::containing($day->format('Y-m-d')));
                    $planner->add($menu, $day->isoWeekday(), Meal::Lunch, DishType::Adhoc, null, 'Comida', 1, [['ingredient' => $eggs, 'quantity' => Quantity::of((string) $c, Unit::Piece), 'note' => null]]);
                    foreach (['fridge', 'pantry', 'freezer'] as $from) {
                        if (isset($model[$from]) && $c > 0) {
                            $taken = min($c, $model[$from]['e']);
                            $model[$from]['e'] -= $taken;
                            $c -= $taken;
                        }
                    }
            }

            $this->travelTo($day->setTime(16, 0));
            $estimate = estimateOf($eggs);
            $expected = array_map(fn (array $row) => (string) $row['e'], $model);
            ksort($expected);

            expect($estimate['rows'])->toBe($expected, "seed {$seed}, step {$step}")
                ->and((int) $estimate['total'])->toBeLessThanOrEqual((int) registeredOf($eggs))
                ->and(array_filter($model, fn (array $row) => $row['e'] < 0 || $row['e'] > $row['r']))->toBe([]);

            $day = $day->addDay();
        }

        // Every row counted with what is registered, in a random order: the estimate is what is registered.
        $this->travelTo($day->setTime(10, 0));
        $rows = InventoryItem::query()->where('ingredient_id', $eggs->id)->get()->shuffle($seed)->all();

        foreach ($rows as $row) {
            $stock->update($row, $row->toQuantity(), $row->location, null, counted: true);
        }

        expect(estimateOf($eggs)['total'])->toBe(registeredOf($eggs), "seed {$seed}: all rows counted");
    }
});

test('editing only the label of a row with an estimate is not a count', function () {
    startTwoLocations();
    $row = eggRow('pantry');
    $this->put(route('inventory.update', $row), ['quantity' => '6', 'unit' => 'piece', 'location' => 'pantry', 'label' => 'Docena'])->assertSessionHasNoErrors();

    expect(estimateOf($this->huevos))->toBe(['total' => '6', 'rows' => ['fridge' => '0', 'pantry' => '6']])
        ->and($row->fresh()->quantity)->toBe('12.000')
        ->and($row->fresh()->label)->toBe('Docena')
        ->and(StockEvent::query()->where('kind', StockEvent::COUNT)->count())->toBe(0);
});
