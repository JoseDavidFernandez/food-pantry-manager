<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Menu;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Quantity;
use Inertia\Testing\AssertableInertia as Assert;

const LIST_WEEK = '2026-10-19';

const LIST_END = '2026-10-25';

beforeEach(function () {
    // Monday 19 Oct 2026, 10:00 in Madrid: the whole week is still ahead (RN-36).
    $this->travelTo('2026-10-19 08:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $this->tomate = Ingredient::factory()->for($this->household)->named('Tomate frito')->create();
    $this->pollo = Ingredient::factory()->for($this->household)->named('Contramuslos de pollo')->create();
});

/**
 * Plans a simple dish with the given ingredient lines on Monday lunch (or another slot).
 *
 * @param  list<array{ingredient: string, quantity: string, unit: string}>  $ingredients
 */
function planDish(array $ingredients, int $day = 1, string $meal = 'lunch', string $name = 'Plato'): void
{
    planInSlot(['week' => LIST_WEEK, 'day' => $day, 'meal' => $meal], [
        'type' => 'adhoc', 'name' => $name, 'servings' => 2, 'ingredients' => $ingredients,
    ])->assertSessionHasNoErrors();
}

/**
 * Opens the persistent list of the week (RN-36): it is created the first time and reconciled
 * with the current needs every time. It replaced closing the menu.
 */
function syncList(): mixed
{
    return test()->get(route('shopping-list.period', ['start' => LIST_WEEK, 'end' => LIST_END]));
}

function listId(): int
{
    return (int) ShoppingList::query()->whereDate('period_start', LIST_WEEK)->whereDate('period_end', LIST_END)->value('id');
}

/**
 * Visible lines of the list keyed by name, as the page receives them.
 *
 * @return array<string, array<string, mixed>>
 */
function listLines(): array
{
    $lines = [];

    syncList()
        ->assertInertia(function (Assert $page) use (&$lines) {
            $lines = collect($page->toArray()['props']['items'])->keyBy('name')->all();
        });

    return $lines;
}

function listItem(string $name): ShoppingListItem
{
    // Opening the list first, as the user does: it reflects the current needs.
    syncList();

    return ShoppingListItem::query()->where('name', $name)->firstOrFail();
}

test('a full week needs no confirmation and compatible needs are added in one line', function () {
    foreach (range(1, 7) as $day) {
        foreach (['lunch', 'dinner'] as $meal) {
            planDish([['ingredient' => 'Arroz', 'quantity' => '100', 'unit' => 'g']], $day, $meal);
        }
    }

    syncList()->assertOk();

    // RN-06: same unit is kept when adding.
    expect(listLines()['Arroz']['display'])->toBe('1,4 kg');
});

test('CA-08: needs minus stock; ingredients with enough stock are left out', function () {
    InventoryItem::factory()->for($this->arroz)->of('2', Unit::Kilogram, StorageLocation::Pantry)->create();
    InventoryItem::factory()->for($this->pollo)->of('200', Unit::Gram, StorageLocation::Fridge)->create();

    planDish([
        ['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g'],
        ['ingredient' => 'Contramuslos de pollo', 'quantity' => '0,8', 'unit' => 'kg'],
    ]);
    syncList();

    $lines = listLines();

    expect($lines)->not->toHaveKey('Arroz')
        ->and($lines['Contramuslos de pollo'])->toMatchArray([
            'display' => '600 g', 'is_generated' => true, 'has_ingredient' => true, 'needs_review' => false,
        ]);
});

test('CA-08: with no stock the whole need goes to the list', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    expect(listLines()['Arroz'])->toMatchArray(['quantity' => '600', 'unit' => 'g', 'display' => '600 g']);
});

test('CA-09: indivisible units are rounded up (1.5 jars → 2 jars)', function () {
    planDish([
        ['ingredient' => 'Tomate frito', 'quantity' => '1,5', 'unit' => 'jar'],
        ['ingredient' => 'Contramuslos de pollo', 'quantity' => '2,25', 'unit' => 'piece'],
    ]);
    syncList();

    $lines = listLines();

    expect($lines['Tomate frito']['display'])->toBe('2 botes')
        ->and($lines['Contramuslos de pollo']['display'])->toBe('3 unidades');
});

test('rounding happens after subtracting the stock', function () {
    InventoryItem::factory()->for($this->tomate)->of('1', Unit::Jar, StorageLocation::Pantry)->create();

    planDish([['ingredient' => 'Tomate frito', 'quantity' => '2,5', 'unit' => 'jar']]);
    syncList();

    // 2.5 − 1 = 1.5 → 2 jars.
    expect(listLines()['Tomate frito']['display'])->toBe('2 botes');
});

test('recipes are scaled to the planned servings before generating the list', function () {
    $recipe = Recipe::factory()->for($this->household)
        ->withIngredients([[$this->arroz, Quantity::of('320', Unit::Gram)]])
        ->create(['name' => 'Pollo con arroz', 'base_servings' => 4]);

    planInSlot(['week' => LIST_WEEK, 'day' => 1, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 6,
    ]);
    syncList();

    expect(listLines()['Arroz']['display'])->toBe('480 g');
});

test('RN-22: stock in a non-convertible unit is not subtracted and the line is flagged', function () {
    InventoryItem::factory()->for($this->tomate)->of('2', Unit::Jar, StorageLocation::Pantry)->create();

    planDish([['ingredient' => 'Tomate frito', 'quantity' => '300', 'unit' => 'g']]);
    syncList();

    expect(listLines()['Tomate frito'])->toMatchArray([
        'display' => '300 g', 'needs_review' => true, 'review_reason' => 'incompatible_inventory',
    ]);
});

test('the recalculation follows the new menu: new needs added, untouched lines updated or removed', function () {
    planDish([
        ['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g'],
        ['ingredient' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar'],
    ]);
    syncList();

    planDish([
        ['ingredient' => 'Arroz', 'quantity' => '800', 'unit' => 'g'],
        ['ingredient' => 'Contramuslos de pollo', 'quantity' => '4', 'unit' => 'piece'],
    ]);
    syncList();

    $lines = listLines();

    expect($lines)->toHaveKeys(['Arroz', 'Contramuslos de pollo'])
        ->not->toHaveKey('Tomate frito')
        ->and($lines['Arroz'])->toMatchArray(['display' => '800 g', 'needs_review' => false])
        ->and($lines['Contramuslos de pollo']['display'])->toBe('4 unidades');
});

test('CA-11: a manual line survives the recalculation and is not duplicated when the menu needs it', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Papel de cocina'])->assertSessionHasNoErrors();
    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar'])->assertSessionHasNoErrors();
    planDish([['ingredient' => 'Tomate frito', 'quantity' => '2', 'unit' => 'jar']], day: 2);
    syncList();

    $lines = listLines();

    expect($lines['Papel de cocina'])->toMatchArray(['is_generated' => false, 'has_ingredient' => false, 'quantity' => null])
        ->and($lines['Tomate frito'])->toMatchArray([
            'is_generated' => false, 'display' => '1 bote', 'calculated' => '2 botes',
            'needs_review' => true, 'review_reason' => 'manual_with_calculation',
        ])
        ->and(ShoppingListItem::where('name', 'Tomate frito')->count())->toBe(1);
});

test('CA-11: an edited generated line keeps the user quantity and is flagged when the calculation changes', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $item = listItem('Arroz');
    $this->patch(route('shopping-lists.items.update', ['list' => listId(), 'item' => $item->id]), ['quantity' => '1', 'unit' => 'kg'])
        ->assertSessionHasNoErrors();

    // Same calculation: the user's quantity is kept without warnings.
    syncList();
    expect(listLines()['Arroz'])->toMatchArray(['display' => '1 kg', 'is_user_modified' => true, 'needs_review' => false]);

    // New calculation: kept, but flagged with the new value.
    planDish([['ingredient' => 'Arroz', 'quantity' => '900', 'unit' => 'g']]);
    syncList();

    expect(listLines()['Arroz'])->toMatchArray([
        'display' => '1 kg', 'calculated' => '900 g', 'needs_review' => true, 'review_reason' => 'calculation_changed',
    ]);

    $this->patch(route('shopping-lists.items.reviewed', ['list' => listId(), 'item' => $item->id]));
    expect(listLines()['Arroz'])->toMatchArray(['display' => '1 kg', 'needs_review' => false]);
});

test('an edited line that is no longer needed is kept and flagged', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();
    $this->patch(route('shopping-lists.items.update', ['list' => listId(), 'item' => listItem('Arroz')->id]), ['quantity' => '700', 'unit' => 'g']);
    planDish([['ingredient' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar']]);
    syncList();

    expect(listLines()['Arroz'])->toMatchArray(['display' => '700 g', 'needs_review' => true, 'review_reason' => 'no_longer_needed']);
});

test('CA-12: a purchased line is unmarked when the need grows and stays purchased when it shrinks', function () {
    planDish([
        ['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g'],
        ['ingredient' => 'Tomate frito', 'quantity' => '3', 'unit' => 'jar'],
    ]);
    syncList();

    foreach (['Arroz', 'Tomate frito'] as $name) {
        $this->patch(route('shopping-lists.items.purchased', ['list' => listId(), 'item' => listItem($name)->id]));
    }
    planDish([
        ['ingredient' => 'Arroz', 'quantity' => '900', 'unit' => 'g'],
        ['ingredient' => 'Tomate frito', 'quantity' => '2', 'unit' => 'jar'],
    ]);
    syncList();

    $lines = listLines();

    expect($lines['Arroz'])->toMatchArray([
        'display' => '900 g', 'is_purchased' => false, 'needs_review' => true, 'review_reason' => 'quantity_increased',
    ])
        ->and($lines['Tomate frito'])->toMatchArray(['display' => '2 botes', 'is_purchased' => true, 'needs_review' => false]);
});

test('a purchased line that is no longer needed is kept and flagged', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();
    $this->patch(route('shopping-lists.items.purchased', ['list' => listId(), 'item' => listItem('Arroz')->id]));
    planDish([['ingredient' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar']]);
    syncList();

    expect(listLines()['Arroz'])->toMatchArray(['is_purchased' => true, 'needs_review' => true, 'review_reason' => 'no_longer_needed']);
});

test('CA-13: marking as purchased never changes the inventory', function () {
    $stock = InventoryItem::factory()->for($this->arroz)->of('100', Unit::Gram, StorageLocation::Pantry)->create();

    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $item = listItem('Arroz');
    $this->patch(route('shopping-lists.items.purchased', ['list' => listId(), 'item' => $item->id]))->assertRedirect();

    expect($item->fresh()->is_purchased)->toBeTrue()
        ->and(InventoryItem::count())->toBe(1)
        ->and($stock->fresh()->toQuantity()->format())->toBe('100 g');

    $this->patch(route('shopping-lists.items.purchased', ['list' => listId(), 'item' => $item->id]));
    expect($item->fresh()->is_purchased)->toBeFalse();
});

test('removing a generated line hides it; it only comes back if the calculation changes', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $this->delete(route('shopping-lists.items.destroy', ['list' => listId(), 'item' => listItem('Arroz')->id]))->assertRedirect();
    expect(listLines())->not->toHaveKey('Arroz');
    syncList();
    expect(listLines())->not->toHaveKey('Arroz');
    planDish([['ingredient' => 'Arroz', 'quantity' => '750', 'unit' => 'g']]);
    syncList();

    expect(listLines()['Arroz'])->toMatchArray(['display' => '750 g', 'needs_review' => true, 'review_reason' => 'calculation_changed']);
});

test('removing a manual line deletes it', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();
    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Papel de cocina', 'quantity' => '2', 'unit' => 'pack']);

    $this->delete(route('shopping-lists.items.destroy', ['list' => listId(), 'item' => listItem('Papel de cocina')->id]));

    expect(ShoppingListItem::where('name', 'Papel de cocina')->exists())->toBeFalse();
});

test('RN-24: adding an ingredient already on the list updates that line instead of duplicating it', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'arroz', 'quantity' => '0,4', 'unit' => 'kg'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', '«Arroz» ya estaba en la lista: se ha actualizado esa línea.');

    expect(ShoppingListItem::where('ingredient_id', $this->arroz->id)->count())->toBe(1)
        ->and(listLines()['Arroz'])->toMatchArray(['display' => '1 kg', 'is_generated' => true, 'is_user_modified' => true]);
});

test('an ingredient in an incompatible unit becomes a separate line', function () {
    planDish([['ingredient' => 'Tomate frito', 'quantity' => '300', 'unit' => 'g']]);
    syncList();

    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar'])
        ->assertSessionHasNoErrors();

    expect(ShoppingListItem::where('ingredient_id', $this->tomate->id)->count())->toBe(2);
});

test('catalog ingredients need a quantity; free text does not', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frito'])->assertSessionHasErrors('quantity');
    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frito', 'quantity' => '1'])->assertSessionHasErrors('unit');
    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Servilletas'])->assertSessionHasNoErrors();

    expect(listLines()['Servilletas'])->toMatchArray(['has_ingredient' => false, 'quantity' => null, 'display' => null]);
});

test('a name similar to a catalog ingredient asks before adding it as free text', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frit', 'quantity' => '1', 'unit' => 'jar'])
        ->assertSessionHasErrors('item');
    expect(ShoppingListItem::where('name', 'Tomate frit')->exists())->toBeFalse();

    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frit', 'quantity' => '1', 'unit' => 'jar', 'free_text' => '1'])
        ->assertSessionHasNoErrors();
    expect(listLines()['Tomate frit']['has_ingredient'])->toBeFalse();
});

test('ingredient lines can only be edited within their unit group', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $item = listItem('Arroz');

    $this->patch(route('shopping-lists.items.update', ['list' => listId(), 'item' => $item->id]), ['quantity' => '2', 'unit' => 'pack'])
        ->assertSessionHasErrors('unit');
    $this->patch(route('shopping-lists.items.update', ['list' => listId(), 'item' => $item->id]), ['quantity' => '', 'unit' => 'g'])
        ->assertSessionHasErrors('quantity');
    $this->patch(route('shopping-lists.items.update', ['list' => listId(), 'item' => $item->id]), ['quantity' => '0,750', 'unit' => 'kg'])
        ->assertSessionHasNoErrors();

    // The unit chosen by the user is kept.
    expect(listLines()['Arroz']['display'])->toBe('0,75 kg');
});

test('an ingredient used in a shopping list cannot be deleted', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();

    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar']);

    $this->delete(route('ingredients.destroy', $this->tomate))->assertRedirect()->assertInertiaFlash('toast.type', 'error');

    expect($this->tomate->fresh())->not->toBeNull();
});

test('a purchased line that is needed again loses the «no longer needed» warning', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();
    $this->patch(route('shopping-lists.items.purchased', ['list' => listId(), 'item' => listItem('Arroz')->id]));
    planDish([['ingredient' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar']]);
    syncList();
    expect(listLines()['Arroz']['review_reason'])->toBe('no_longer_needed');
    planDish([['ingredient' => 'Arroz', 'quantity' => '500', 'unit' => 'g']]);
    syncList();

    expect(listLines()['Arroz'])->toMatchArray(['display' => '500 g', 'is_purchased' => true, 'needs_review' => false, 'review_reason' => null]);
});

test('a manual line drops its stale calculation when the menu no longer needs it', function () {
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    syncList();
    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar']);
    planDish([['ingredient' => 'Tomate frito', 'quantity' => '2', 'unit' => 'jar']], day: 2);
    syncList();
    expect(listLines()['Tomate frito']['review_reason'])->toBe('manual_with_calculation');
    $this->delete(route('menu.dishes.destroy', dishIn(['week' => LIST_WEEK, 'day' => 2, 'meal' => 'lunch'])));
    syncList();

    expect(listLines()['Tomate frito'])->toMatchArray(['display' => '1 bote', 'calculated' => null, 'needs_review' => false, 'review_reason' => null]);
});

/**
 * RN-35/RN-36: the persistent list follows the stock edited in Inventory.
 */
function eggsScenario(): InventoryItem
{
    $huevos = Ingredient::factory()->for(test()->household)->named('Huevos')->create();
    $stock = InventoryItem::factory()->for($huevos)->of('2', Unit::Piece, StorageLocation::Fridge)->create();
    planDish([['ingredient' => 'Huevos', 'quantity' => '2', 'unit' => 'piece']]);
    syncList();

    return $stock;
}

test('regression RN-35: lowering the eggs in stock adds them to the list; enough stock again removes them', function () {
    $stock = eggsScenario();
    expect(listLines())->not->toHaveKey('Huevos');

    $this->put(route('inventory.update', $stock), ['quantity' => '1', 'unit' => 'piece', 'location' => 'fridge'])
        ->assertSessionHasNoErrors();
    expect(listLines()['Huevos'])->toMatchArray(['display' => '1 unidad', 'is_user_modified' => false, 'needs_review' => false]);

    // The quick adjustment works the same way: back to 2, nothing to buy.
    $this->patch(route('inventory.adjust', $stock), ['step' => 'up'])->assertSessionHasNoErrors();
    expect(listLines())->not->toHaveKey('Huevos');

    // So does adding stock: down to 1 again, then +3 makes the line disappear.
    $this->patch(route('inventory.adjust', $stock), ['step' => 'down'])->assertSessionHasNoErrors();
    expect(listLines()['Huevos']['display'])->toBe('1 unidad');
    $this->post(route('inventory.store'), ['ingredient' => 'Huevos', 'location' => 'fridge', 'quantity' => '3', 'unit' => 'piece'])
        ->assertSessionHasNoErrors();
    expect(listLines())->not->toHaveKey('Huevos');

    // Deleting the stock brings back the whole need, in a single line.
    $this->delete(route('inventory.destroy', $stock))->assertRedirect();
    expect(listLines()['Huevos']['display'])->toBe('2 unidades')
        ->and(ShoppingListItem::query()->where('name', 'Huevos')->count())->toBe(1);
});

test('RN-35: compatible needs of several dishes stay in one line and units are converted', function () {
    $stock = InventoryItem::factory()->for($this->arroz)->of('1', Unit::Kilogram, StorageLocation::Pantry)->create();
    planDish([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    planDish([['ingredient' => 'Arroz', 'quantity' => '0,3', 'unit' => 'kg']], day: 2);
    syncList();
    expect(listLines())->not->toHaveKey('Arroz');

    $this->put(route('inventory.update', $stock), ['quantity' => '500', 'unit' => 'g', 'location' => 'pantry']);

    expect(listLines()['Arroz']['display'])->toBe('400 g')
        ->and(ShoppingListItem::query()->where('name', 'Arroz')->count())->toBe(1);
});

test('RN-35: manual lines and quantities changed by the user are kept; changes are flagged, not overwritten', function () {
    $stock = eggsScenario();
    $this->post(route('shopping-lists.items.store', listId()), ['item' => 'Papel de cocina'])->assertSessionHasNoErrors();
    $this->put(route('inventory.update', $stock), ['quantity' => '1', 'unit' => 'piece', 'location' => 'fridge']);

    $huevos = listItem('Huevos');
    $this->patch(route('shopping-lists.items.update', ['list' => listId(), 'item' => $huevos->id]), ['quantity' => '12', 'unit' => 'piece'])
        ->assertSessionHasNoErrors();

    // Now there is no egg at all: the need grows to 2, but the user's 12 stay, flagged.
    $this->delete(route('inventory.destroy', $stock));

    expect(listLines()['Huevos'])->toMatchArray([
        'display' => '12 unidades', 'calculated' => '2 unidades', 'is_user_modified' => true,
        'needs_review' => true, 'review_reason' => 'calculation_changed',
    ])->and(listLines())->toHaveKey('Papel de cocina');
});

test('RN-35 and RN-28: a purchased line stays purchased when the need shrinks and is unmarked when it grows', function () {
    $stock = eggsScenario();
    $this->put(route('inventory.update', $stock), ['quantity' => '1', 'unit' => 'piece', 'location' => 'fridge']);
    $this->patch(route('shopping-lists.items.purchased', ['list' => listId(), 'item' => listItem('Huevos')->id]));

    // Need grows (1 → 2): a new need is never hidden behind the purchased mark.
    $this->delete(route('inventory.destroy', $stock));
    expect(listLines()['Huevos'])->toMatchArray([
        'display' => '2 unidades', 'is_purchased' => false, 'needs_review' => true, 'review_reason' => 'quantity_increased',
    ]);

    // Marked again; the need shrinks (2 → 1): it stays purchased. The stock is never touched by the list.
    $this->patch(route('shopping-lists.items.purchased', ['list' => listId(), 'item' => listItem('Huevos')->id]));
    $this->post(route('inventory.store'), ['ingredient' => 'Huevos', 'location' => 'fridge', 'quantity' => '1', 'unit' => 'piece']);
    expect(listLines()['Huevos'])->toMatchArray(['display' => '1 unidad', 'is_purchased' => true])
        ->and(InventoryItem::sole()->quantity)->toBe('1.000');
});
