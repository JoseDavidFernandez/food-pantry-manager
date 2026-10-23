<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Quantity;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CA-35, RN-44 (Phase A): estimated stock from the planned meals, without confirming anything. Cases
 * 1 to 9 of the specification: a tortilla of 4 eggs for lunch from Monday to Friday of the week
 * of 19 Oct 2026, 18 eggs registered on Monday at 08:00, «now» Thursday 22 at 10:00 (Madrid).
 */
beforeEach(function () {
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
    $this->huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
    $this->tortilla = Recipe::factory()->for($this->household)->withIngredients([[$this->huevos, Quantity::of('4', Unit::Piece)]])
        ->create(['name' => 'Tortilla', 'base_servings' => 2]);

    foreach ([1, 2, 3, 4, 5] as $day) {
        $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => $day, 'meal' => 'lunch']), [
            'type' => 'recipe', 'recipe_id' => $this->tortilla->id, 'servings' => 2,
        ])->assertSessionHasNoErrors();
    }
});

function stockEggs(string $quantity, string $location = 'fridge'): void
{
    test()->post(route('inventory.store'), ['ingredient' => 'Huevos', 'quantity' => $quantity, 'unit' => 'piece', 'location' => $location])
        ->assertSessionHasNoErrors();
}

function countEggs(string $quantity, string $location = 'fridge'): void
{
    $row = InventoryItem::query()->where('ingredient_id', test()->huevos->id)->where('location', $location)->sole();
    test()->patch(route('inventory.adjust', $row), ['quantity' => $quantity])->assertSessionHasNoErrors();
}

/**
 * What the 7-day list buys of eggs, and what it says is at home.
 *
 * @return array{buy: string|null, have: string|null, registered: string|null, committed: string|null}
 */
function eggList(): array
{
    $line = collect(test()->get(route('shopping-list.period', ['days' => 7]))->viewData('page')['props']['items'])->firstWhere('name', 'Huevos');

    return [
        'buy' => $line['display'] ?? null,
        'have' => $line['detail']['have'] ?? null,
        'registered' => $line['detail']['registered'] ?? null,
        'committed' => $line['detail']['committed'] ?? null,
    ];
}

function eggDishOn(int $day, string $meal = 'lunch'): int
{
    return dishIn(['week' => '2026-10-19', 'day' => $day, 'meal' => $meal]);
}

test('case 1: Monday to Wednesday count as eaten, without confirming anything', function () {
    stockEggs('18');
    atMadrid('2026-10-22 10:00');

    expect(estimateOf($this->huevos)['total'])->toBe('6')
        ->and(registeredOf($this->huevos))->toBe('18')
        ->and(eggList())->toBe(['buy' => '2 unidades', 'have' => '6 unidades', 'registered' => '18 unidades', 'committed' => '8 unidades']);
});

test('case 2: a count is a new starting point, also on the same day of a meal', function () {
    stockEggs('18');

    // 2a: Wednesday 20:00, after lunch: the count already reflects that lunch.
    atMadrid('2026-10-21 20:00');
    countEggs('5');
    atMadrid('2026-10-22 10:00');
    expect(estimateOf($this->huevos)['total'])->toBe('5')
        ->and(eggList()['buy'])->toBe('3 unidades');
});

test('case 2b: a count before the meal of that day is followed by that meal', function () {
    stockEggs('18');
    atMadrid('2026-10-21 12:00');
    countEggs('9');
    atMadrid('2026-10-22 10:00');

    expect(estimateOf($this->huevos)['total'])->toBe('5')
        ->and(registeredOf($this->huevos))->toBe('9');
});

test('case 3: a purchase adds without resetting what was eaten before', function () {
    stockEggs('18');
    atMadrid('2026-10-21 18:00');
    stockEggs('12');
    atMadrid('2026-10-22 10:00');

    expect(estimateOf($this->huevos)['total'])->toBe('18')
        ->and(registeredOf($this->huevos))->toBe('30')
        ->and(eggList()['buy'])->toBeNull();
});

test('case 3b: meals beyond what was at home are a shortfall; a later purchase is not eaten by them', function () {
    stockEggs('6');
    atMadrid('2026-10-21 09:00');
    stockEggs('12');
    atMadrid('2026-10-22 10:00');

    // 6 − 4 = 2 → 2 − 4 = 0 (2 short) → + 12 → − 4 (Wednesday) = 8.
    expect(estimateOf($this->huevos)['total'])->toBe('8')
        ->and(registeredOf($this->huevos))->toBe('18');
});

test('case 4: «No comido» days later gives the eggs back, and undoing it takes them again', function () {
    stockEggs('18');
    atMadrid('2026-10-22 10:00');

    $this->patch(route('menu.dishes.eaten', eggDishOn(2)), ['eaten' => '0']);
    expect(estimateOf($this->huevos)['total'])->toBe('10');

    $this->patch(route('menu.dishes.eaten', eggDishOn(2)), ['eaten' => '1']);
    expect(estimateOf($this->huevos)['total'])->toBe('6');
});

test('case 4b: «No comido» on a meal before a count changes nothing', function () {
    stockEggs('18');
    atMadrid('2026-10-21 20:00');
    countEggs('5');
    atMadrid('2026-10-22 10:00');

    $this->patch(route('menu.dishes.eaten', eggDishOn(2)), ['eaten' => '0']);
    expect(estimateOf($this->huevos)['total'])->toBe('5');
});

test('case 5: changing or removing a past dish is an explicit correction of that dish', function () {
    stockEggs('18');
    atMadrid('2026-10-22 10:00');

    $this->put(route('menu.dishes.update', eggDishOn(3)), ['type' => 'recipe', 'recipe_id' => $this->tortilla->id, 'servings' => 1]);
    expect(estimateOf($this->huevos)['total'])->toBe('8');

    $this->delete(route('menu.dishes.destroy', eggDishOn(3)));
    expect(estimateOf($this->huevos)['total'])->toBe('10');
});

test('case 6: editing the recipe never changes what past meals used (RN-45)', function () {
    stockEggs('18');
    atMadrid('2026-10-22 10:00');

    $this->put(route('recipes.update', $this->tortilla), [
        'name' => 'Tortilla', 'base_servings' => 2,
        'ingredients' => [['ingredient' => 'Huevos', 'quantity' => '5', 'unit' => 'piece']],
    ])->assertSessionHasNoErrors();

    // Monday to Wednesday keep 4 eggs; Thursday and Friday need 5 each: 10 − 6 = 4.
    expect(estimateOf($this->huevos)['total'])->toBe('6')
        ->and(eggList()['buy'])->toBe('4 unidades');
});

test('cases 7 and 8: the estimate never goes below zero and the list buys all that is needed', function () {
    stockEggs('6');
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 2, 'meal' => 'dinner']), [
        'type' => 'adhoc', 'name' => 'Flan', 'servings' => 2,
        'ingredients' => [['ingredient' => 'Huevos', 'quantity' => '2', 'unit' => 'piece']],
    ])->assertSessionHasNoErrors();
    atMadrid('2026-10-22 10:00');

    expect(estimateOf($this->huevos)['total'])->toBe('0')
        ->and(registeredOf($this->huevos))->toBe('6')
        ->and(eggList())->toBe(['buy' => '8 unidades', 'have' => null, 'registered' => '6 unidades', 'committed' => '8 unidades']);

    // Shown as an estimate in the inventory, with the meals that explain it.
    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('items.0.estimate.display', '0 unidades')
        ->where('items.0.estimate.registered', '6 unidades')
        ->has('items.0.estimate.consumed', 2)
        ->where('items.0.estimate.consumed.0.dish', 'Tortilla'));
});

test('case 9: at the reference time a meal goes from the list to the estimate, never both', function () {
    stockEggs('18');

    atMadrid('2026-10-22 14:59');
    expect(estimateOf($this->huevos)['total'])->toBe('6')
        ->and(eggList())->toMatchArray(['buy' => '2 unidades', 'committed' => '8 unidades']);

    atMadrid('2026-10-22 15:01');
    expect(estimateOf($this->huevos)['total'])->toBe('2')
        ->and(eggList())->toMatchArray(['buy' => '2 unidades', 'committed' => '4 unidades']);
});

test('the registered rows never change by themselves, and a household starts from what is registered', function () {
    stockEggs('18');
    atMadrid('2026-10-24 10:00');

    expect(InventoryItem::sole()->quantity)->toBe('18.000')
        ->and(estimateOf($this->huevos)['total'])->toBe('0');

    // Another household with stock and no meals: its estimate is its registered stock.
    $other = User::factory()->withHousehold()->create();
    $sal = Ingredient::factory()->for($other->currentHousehold())->named('Sal')->create();
    InventoryItem::factory()->for($sal)->of('500', Unit::Gram)->create();
    expect(estimateOf($sal)['total'])->toBe('500');
});

test('the needs of the menu only count pending dishes: past ones are already in the estimate', function () {
    stockEggs('18');
    atMadrid('2026-10-22 10:00');

    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->where('requirements.lines.0.ingredient', 'Huevos')
        // Thursday and Friday: 8 needed, ≈ 6 at home (18 registered), 2 missing. Never 20 − 6.
        ->where('requirements.lines.0.need', '8 unidades')
        ->where('requirements.lines.0.have', '6 unidades')
        ->where('requirements.lines.0.registered', '18 unidades')
        ->where('requirements.lines.0.missing', '2 unidades'));
});
