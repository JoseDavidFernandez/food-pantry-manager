<?php

use App\Actions\Inventory\FoodRestock;
use App\Enums\HouseholdRole;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\StockEvent;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Str;

/**
 * RN-47 (Phase B, B4), CA-39 to CA-41: staple foods. «Mínimo en casa» keeps at least a quantity at home;
 * «Se acabó» counts every location to zero and, without a minimum, asks for the food once,
 * until a purchase of it is added. Always on the same line as the recipes that need it.
 */
beforeEach(function () {
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
    $this->huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
    $this->leche = Ingredient::factory()->for($this->household)->named('Leche')->create();
    InventoryItem::factory()->for($this->huevos)->of('6', Unit::Piece, StorageLocation::Fridge)->create();
    InventoryItem::factory()->for($this->huevos)->of('6', Unit::Piece, StorageLocation::Pantry)->create();
    InventoryItem::factory()->for($this->leche)->of('3', Unit::Litre, StorageLocation::Pantry)->create();
});

/**
 * @return array<string, array<string, mixed>> lines of a period list, by name
 */
function stapleLines(int $days = 7): array
{
    return collect(test()->get(route('shopping-list.period', ['days' => $days]))->viewData('page')['props']['items'])->keyBy('name')->all();
}

function minimumOf(Ingredient $ingredient, ?string $quantity, ?string $unit = null): mixed
{
    return test()->put(route('ingredients.minimum', $ingredient), ['quantity' => $quantity, 'unit' => $unit]);
}

test('a minimum with enough at home asks for nothing; «Se acabó» brings it to the list until it is bought', function () {
    minimumOf($this->leche, '2', 'l')->assertSessionHasNoErrors();
    expect(stapleLines())->not->toHaveKey('Leche');

    $this->post(route('ingredients.depleted', $this->leche))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', '«Leche»: 0 en casa. Se pedirá en la lista para volver a tener el mínimo.');

    $line = stapleLines()['Leche'];
    expect($line['display'])->toBe('2 l')
        ->and($line['detail'])->toMatchArray(['restock' => '2 l', 'restock_mode' => 'minimum', 'recipes' => null])
        ->and($this->leche->fresh()->restock_mode)->toBe('minimum');

    // Bought: the estimate is back over the minimum and the line leaves the list.
    $this->post(route('inventory.store'), ['ingredient' => 'Leche', 'quantity' => '2', 'unit' => 'l', 'location' => 'pantry']);
    expect(stapleLines())->not->toHaveKey('Leche');

    // Removing the minimum: nothing is asked for any more.
    minimumOf($this->leche, null)->assertSessionHasNoErrors();
    expect($this->leche->fresh()->restock_mode)->toBeNull();
});

test('a minimum and the recipes share one line: recipes + minimum − what stays at home', function () {
    $tortilla = Recipe::factory()->for($this->household)->withIngredients([[$this->huevos, Quantity::of('4', Unit::Piece)]])
        ->create(['name' => 'Tortilla', 'base_servings' => 1]);
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => $tortilla->id, 'servings' => 1]);
    minimumOf($this->huevos, '6', 'piece');

    // 4 for Tuesday + 6 to keep = 10, with 12 at home: nothing to buy.
    expect(stapleLines())->not->toHaveKey('Huevos');

    $this->post(route('ingredients.depleted', $this->huevos));
    $lines = stapleLines();
    expect($lines['Huevos']['display'])->toBe('10 unidades')
        ->and($lines['Huevos']['detail'])->toMatchArray(['recipes' => '4 unidades', 'restock' => '6 unidades', 'need' => '10 unidades'])
        ->and(ShoppingList::sole()->items()->where('name', 'Huevos')->count())->toBe(1);

    // In cartons of 12, rounded up to whole packs.
    $this->huevos->conversions()->create(['unit' => 'carton', 'factor' => '12', 'to_unit' => 'piece']);
    $this->put(route('ingredients.purchase-unit', $this->huevos), ['purchase_unit' => 'carton']);
    expect(stapleLines()['Huevos']['display'])->toBe('1 cartón');
});

test('«Se acabó» counts every location to zero, once, recorded as counts; the stock can be corrected after', function () {
    $this->post(route('ingredients.depleted', $this->huevos))->assertSessionHasNoErrors();

    expect($this->huevos->inventoryItems()->count())->toBe(0)
        ->and(StockEvent::query()->where('ingredient_id', $this->huevos->id)->where('kind', StockEvent::COUNT)->where('quantity', 0)->count())->toBe(2)
        ->and(estimateOf($this->huevos)['total'])->toBe('0');

    // Corrected by the usual flow: adding stock again.
    $this->post(route('inventory.store'), ['ingredient' => 'Huevos', 'quantity' => '4', 'unit' => 'piece', 'location' => 'fridge']);
    expect(estimateOf($this->huevos)['total'])->toBe('4');
});

test('without a minimum, «Se acabó» asks for it once, and the first purchase of it ends that', function () {
    $this->post(route('ingredients.depleted', $this->huevos))
        ->assertInertiaFlash('toast.message', '«Huevos»: 0 en casa. Se ha añadido a la lista (6 unidades) hasta que registres su compra.');

    // What was there (no previous purchase): 6, in the 7- and 14-day lists alike.
    expect(stapleLines()['Huevos'])->toMatchArray(['display' => '6 unidades'])
        ->and(stapleLines(14)['Huevos']['detail']['restock_mode'])->toBe('once');

    // Bought from the list an hour later: received, and not asked for again.
    atMadrid('2026-10-19 09:00');
    $list = ShoppingList::query()->where('rolling_days', 7)->sole();
    $line = $list->items()->where('name', 'Huevos')->sole();
    $this->patch(route('shopping-lists.items.purchased', [$list, $line]));
    $this->post(route('shopping-lists.receptions.checked', $list), [
        'token' => (string) Str::uuid(),
        'lines' => [['item_id' => $line->id, 'quantity' => '12', 'unit' => 'piece', 'location' => 'fridge']],
    ])->assertSessionHasNoErrors();

    expect(stapleLines())->not->toHaveKey('Huevos')
        ->and(app(FoodRestock::class)->active($this->huevos->fresh()))->toBeNull();

    // The next «Se acabó» asks for the last purchase: 12.
    atMadrid('2026-10-19 10:00');
    $this->post(route('ingredients.depleted', $this->huevos));
    expect(stapleLines()['Huevos']['display'])->toBe('12 unidades');
});

test('a minimum line removed from a list stays removed there until the calculation changes', function () {
    minimumOf($this->leche, '2', 'l');
    $this->post(route('ingredients.depleted', $this->leche));
    stapleLines();
    $list = ShoppingList::query()->where('rolling_days', 7)->sole();
    $this->delete(route('shopping-lists.items.destroy', [$list, $list->items()->where('name', 'Leche')->sole()]));

    expect(stapleLines())->not->toHaveKey('Leche');

    minimumOf($this->leche, '3', 'l');
    expect(stapleLines()['Leche']['display'])->toBe('3 l');
});

test('only members who can write change staples, and only in their household', function () {
    $viewer = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create();
    $this->actingAs($viewer);
    minimumOf($this->leche, '2', 'l')->assertForbidden();
    $this->post(route('ingredients.depleted', $this->leche))->assertForbidden();

    $this->actingAs(User::factory()->withHousehold()->create());
    minimumOf($this->leche, '2', 'l')->assertForbidden();
    $this->post(route('ingredients.depleted', $this->leche))->assertForbidden();

    expect($this->leche->fresh()->restock_mode)->toBeNull()
        ->and($this->leche->inventoryItems()->count())->toBe(1);
});

test('a minimum needs a positive quantity with its unit', function () {
    minimumOf($this->leche, '0', 'l')->assertSessionHasErrors('quantity');
    minimumOf($this->leche, '2')->assertSessionHasErrors('unit');
    expect($this->leche->fresh()->restock_mode)->toBeNull();
});
