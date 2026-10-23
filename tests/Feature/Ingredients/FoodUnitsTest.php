<?php

use App\Enums\HouseholdRole;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Str;

/**
 * CA-28 and CA-29. Points 3 and 4 (2026-10-22): the units of a food (RN-40) and its purchase format (RN-41).
 * Recipes use their unit, the stock keeps its own and the list buys whole packs; one common
 * model, with the conversions of each food only.
 */
beforeEach(function () {
    // Monday 19 Oct 2026 in Madrid: the whole week is ahead.
    $this->travelTo('2026-10-19 08:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
});

/**
 * Plans a recipe with one ingredient for Tuesday's lunch (1 serving = the quantity given).
 */
function planNeed(Ingredient $ingredient, string $amount, Unit $unit, string $name = 'Plato'): void
{
    $recipe = Recipe::factory()->for($ingredient->household)->withIngredients([[$ingredient, Quantity::of($amount, $unit)]])
        ->create(['name' => $name.' '.Str::random(4), 'base_servings' => 1]);

    test()->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch']), [
        'type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 1,
    ])->assertSessionHasNoErrors();
}

function addConversion(Ingredient $ingredient, string $unit, string $factor, string $to): mixed
{
    return test()->post(route('ingredients.conversions.store', $ingredient), ['unit' => $unit, 'factor' => $factor, 'to_unit' => $to]);
}

/**
 * @return array<string, array<string, mixed>> list lines of the next 7 days, by name
 */
function weekLines(): array
{
    return collect(test()->get(route('shopping-list.period', ['days' => 7]))->viewData('page')['props']['items'])
        ->keyBy('name')->all();
}

test('RN-40: garlic in dientes against stock in cabezas is compared once the food knows the conversion', function () {
    $ajo = Ingredient::factory()->for($this->household)->named('Ajo')->create();
    InventoryItem::factory()->for($ajo)->of('1', Unit::Head, StorageLocation::Pantry)->create();
    planNeed($ajo, '3', Unit::Clove);

    // Without a conversion the units cannot be compared: it is bought and flagged for review (RN-22).
    expect(weekLines()['Ajo'])->toMatchArray(['display' => '3 dientes', 'needs_review' => true]);

    addConversion($ajo, 'head', '10', 'clove')->assertSessionHasNoErrors();

    // 1 cabeza = 10 dientes ≥ 3 dientes: nothing to buy, and the stock row keeps its unit.
    expect(weekLines())->toBe([])
        ->and(InventoryItem::sole()->unit)->toBe(Unit::Head);

    planNeed($ajo, '9', Unit::Clove, 'Otro');
    // 12 dientes − 10 = 2 dientes; bought by heads: 1 cabeza, explaining what one holds.
    test()->put(route('ingredients.purchase-unit', $ajo), ['purchase_unit' => 'head'])->assertSessionHasNoErrors();
    $line = weekLines()['Ajo'];

    expect($line['display'])->toBe('1 cabeza')
        ->and($line['needs_review'])->toBeFalse()
        ->and($line['detail']['pack'])->toBe('1 cabeza = 10 dientes')
        ->and($line['detail']['missing'])->toBe('2 dientes')
        ->and($line['detail']['have'])->toBe('10 dientes');
});

test('RN-41: potatoes in bags of 500 g are bought in whole bags, discounting the stock', function () {
    $patatas = Ingredient::factory()->for($this->household)->named('Patatas')->create();
    planNeed($patatas, '600', Unit::Gram);
    addConversion($patatas, 'bag', '500', 'g')->assertSessionHasNoErrors();
    $this->put(route('ingredients.purchase-unit', $patatas), ['purchase_unit' => 'bag'])->assertSessionHasNoErrors();

    $line = weekLines()['Patatas'];
    expect($line['display'])->toBe('2 bolsas')
        ->and($line['detail']['missing'])->toBe('600 g')
        ->and($line['detail']['pack'])->toBe('1 bolsa = 500 g');

    // A bag at home counts as 500 g: 100 g missing, still one whole bag.
    InventoryItem::factory()->for($patatas)->of('1', Unit::Bag, StorageLocation::Pantry)->create();
    expect(weekLines()['Patatas']['display'])->toBe('1 bolsa');

    // 1 kg at home is enough.
    InventoryItem::factory()->for($patatas)->of('1', Unit::Kilogram, StorageLocation::Fridge)->create();
    expect(weekLines())->toBe([]);
});

test('RN-41: croquetas by units in bags of 20, and eggs in cartons of 12', function () {
    $croquetas = Ingredient::factory()->for($this->household)->named('Croquetas')->create();
    $huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
    planNeed($croquetas, '25', Unit::Piece);
    planNeed($huevos, '8', Unit::Piece);
    InventoryItem::factory()->for($huevos)->of('6', Unit::Piece, StorageLocation::Fridge)->create();

    addConversion($croquetas, 'bag', '20', 'piece')->assertSessionHasNoErrors();
    addConversion($huevos, 'carton', '12', 'piece')->assertSessionHasNoErrors();
    $this->put(route('ingredients.purchase-unit', $croquetas), ['purchase_unit' => 'bag'])->assertSessionHasNoErrors();
    $this->put(route('ingredients.purchase-unit', $huevos), ['purchase_unit' => 'carton'])->assertSessionHasNoErrors();

    $lines = weekLines();
    expect($lines['Croquetas']['display'])->toBe('2 bolsas')
        ->and($lines['Croquetas']['detail']['missing'])->toBe('25 unidades')
        ->and($lines['Huevos']['display'])->toBe('1 cartón')
        ->and($lines['Huevos']['detail']['missing'])->toBe('2 unidades')
        ->and($lines['Huevos']['detail']['pack'])->toBe('1 cartón = 12 unidades');

    // Without a purchase unit the list asks for exactly what is missing (RN-23).
    $this->put(route('ingredients.purchase-unit', $huevos), ['purchase_unit' => ''])->assertSessionHasNoErrors();
    expect(weekLines()['Huevos']['display'])->toBe('2 unidades');
});

test('RN-40: conversions never contradict each other, and only valid purchase units are accepted', function () {
    $ajo = Ingredient::factory()->for($this->household)->named('Ajo')->create();

    addConversion($ajo, 'head', '10', 'clove')->assertSessionHasNoErrors();
    addConversion($ajo, 'net', '3', 'head')->assertSessionHasNoErrors();
    // 1 malla is already 30 dientes through the cabezas: a second path is refused.
    addConversion($ajo, 'net', '25', 'clove')->assertSessionHasErrors('to_unit');
    addConversion($ajo, 'clove', '0.1', 'head')->assertSessionHasErrors('to_unit');
    // g and kg always convert; the same unit is not a conversion; factors must be positive.
    addConversion($ajo, 'kg', '1000', 'g')->assertSessionHasErrors('to_unit');
    addConversion($ajo, 'head', '1', 'head')->assertSessionHasErrors('to_unit');
    addConversion($ajo, 'bag', '0', 'g')->assertSessionHasErrors('factor');
    addConversion($ajo, 'bag', '-2', 'g')->assertSessionHasErrors('factor');

    expect($ajo->conversions()->count())->toBe(2);

    // By weight needs no format; a pack must say first what it holds.
    $this->put(route('ingredients.purchase-unit', $ajo), ['purchase_unit' => 'kg'])->assertSessionHasErrors('purchase_unit');
    $this->put(route('ingredients.purchase-unit', $ajo), ['purchase_unit' => 'bag'])->assertSessionHasErrors('purchase_unit');
    $this->put(route('ingredients.purchase-unit', $ajo), ['purchase_unit' => 'net'])->assertSessionHasNoErrors();
    expect($ajo->refresh()->purchase_unit)->toBe(Unit::Net);

    // Removing what made it convertible clears the purchase unit and says so.
    $this->delete(route('ingredients.conversions.destroy', [$ajo, $ajo->conversions()->where('unit', 'net')->sole()]))
        ->assertInertiaFlash('toast.type', 'warning');
    expect($ajo->refresh()->purchase_unit)->toBeNull()
        ->and($ajo->conversions()->count())->toBe(1);
});

test('the conversions of a food only apply to it, and other households or viewers cannot change them', function () {
    $ajo = Ingredient::factory()->for($this->household)->named('Ajo')->create();
    $cebolla = Ingredient::factory()->for($this->household)->named('Cebolla')->create();
    addConversion($ajo, 'head', '10', 'clove')->assertSessionHasNoErrors();
    InventoryItem::factory()->for($cebolla)->of('1', Unit::Head, StorageLocation::Pantry)->create();
    planNeed($cebolla, '3', Unit::Clove);

    // The garlic conversion is not used for the onion.
    expect(weekLines()['Cebolla']['needs_review'])->toBeTrue();

    $conversion = $ajo->conversions()->sole();
    $other = User::factory()->withHousehold()->create();
    $this->actingAs($other);
    addConversion($ajo, 'bag', '500', 'g')->assertForbidden();
    $this->put(route('ingredients.purchase-unit', $ajo), ['purchase_unit' => 'head'])->assertForbidden();
    $this->delete(route('ingredients.conversions.destroy', [$ajo, $conversion]))->assertForbidden();

    $viewer = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create();
    $this->actingAs($viewer);
    addConversion($ajo, 'bag', '500', 'g')->assertForbidden();
    $this->delete(route('ingredients.conversions.destroy', [$ajo, $conversion]))->assertForbidden();

    expect($ajo->conversions()->count())->toBe(1)->and($ajo->refresh()->purchase_unit)->toBeNull();
});

test('receiving bags adds bags to the stock, counted in grams; a manual add joins the same line', function () {
    $patatas = Ingredient::factory()->for($this->household)->named('Patatas')->create();
    planNeed($patatas, '1200', Unit::Gram);
    addConversion($patatas, 'bag', '500', 'g');
    $this->put(route('ingredients.purchase-unit', $patatas), ['purchase_unit' => 'bag']);

    $lines = weekLines();
    expect($lines['Patatas']['display'])->toBe('3 bolsas');
    $list = ShoppingList::sole();

    // Adding 500 g by hand joins the bag line: 3 + 1 = 4 bags.
    $this->post(route('shopping-lists.items.store', $list), ['item' => 'Patatas', 'quantity' => '500', 'unit' => 'g'])->assertSessionHasNoErrors();
    expect($list->items()->count())->toBe(1)
        ->and($list->items()->sole()->toQuantity()?->format())->toBe('4 bolsas');

    // Receiving 1 kg (two bags' worth) of that line counts against it: 2 bags still missing.
    $this->post(route('shopping-lists.receptions.store', $list), [
        'token' => (string) Str::uuid(),
        'lines' => [['item_id' => $list->items()->sole()->id, 'outcome' => 'received', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry']],
        'extras' => [],
    ])->assertSessionHasNoErrors();

    $line = weekLines()['Patatas'];
    expect($line['remaining'])->toBe('2 bolsas')
        ->and($line['other_units'])->toBeFalse()
        ->and(InventoryItem::sole()->toQuantity()->format())->toBe('1 kg');
});
