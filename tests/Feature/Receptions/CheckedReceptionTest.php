<?php

use App\Actions\Receptions\ReceptionRecorder;
use App\Enums\HouseholdRole;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Reception;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\StockEvent;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CA-38, RN-46 (Phase A): «Guardar lo marcado». What is in the cart goes to the stock in one operation
 * with editable quantities; everything else stays pending without deciding anything. Lunch on
 * Tuesday 20 Oct 2026 needs 8 eggs, 600 g of potatoes (bought in bags of 500 g) and 300 g of rice.
 */
beforeEach(function () {
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create(['category' => IngredientCategory::DairyEggs]);
    $patatas = Ingredient::factory()->for($this->household)->named('Patatas')->create();
    Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $patatas->conversions()->create(['unit' => 'bag', 'factor' => '500', 'to_unit' => 'g']);
    $patatas->update(['purchase_unit' => Unit::Bag]);

    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch']), [
        'type' => 'adhoc', 'name' => 'Comida', 'servings' => 2, 'ingredients' => [
            ['ingredient' => 'Huevos', 'quantity' => '8', 'unit' => 'piece'],
            ['ingredient' => 'Patatas', 'quantity' => '600', 'unit' => 'g'],
            ['ingredient' => 'Arroz', 'quantity' => '300', 'unit' => 'g'],
        ],
    ])->assertSessionHasNoErrors();

    $this->get(route('shopping-list.period'))->assertOk();
    $this->list = ShoppingList::sole();
    $this->post(route('shopping-lists.items.store', $this->list), ['item' => 'Papel de cocina', 'free_text' => '1']);
});

function cartLine(string $name): ShoppingListItem
{
    return test()->list->items()->where('name', $name)->sole();
}

function putInCart(string ...$names): void
{
    foreach ($names as $name) {
        test()->patch(route('shopping-lists.items.purchased', [test()->list, cartLine($name)]));
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function cartListLines(): array
{
    return collect(test()->get(route('shopping-list.period'))->viewData('page')['props']['items'])->keyBy('name')->all();
}

/**
 * @param  array<string, array{0: string, 1: string, 2: string}>  $lines  name => [quantity, unit, location]
 */
function saveChecked(array $lines, ?string $token = null): mixed
{
    return test()->post(route('shopping-lists.receptions.checked', test()->list), [
        'token' => $token ?? (string) Str::uuid(),
        'lines' => collect($lines)->map(fn (array $line, string $name) => [
            'item_id' => cartLine($name)->id, 'quantity' => $line[0], 'unit' => $line[1], 'location' => $line[2],
        ])->values()->all(),
    ]);
}

test('the list suggests what is missing, in packs, and where each food usually goes', function () {
    $lines = cartListLines();

    expect($lines['Huevos']['suggested'])->toBe(['quantity' => '8', 'unit' => 'piece', 'location' => 'fridge'])
        ->and($lines['Patatas']['suggested'])->toBe(['quantity' => '2', 'unit' => 'bag', 'location' => 'pantry'])
        ->and($lines['Papel de cocina']['suggested'])->toBeNull();

    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page
        ->where('checkedToken', fn (string $token) => Str::isUuid($token)));
});

test('the marked lines go to the stock in one operation; the rest stays pending, unmarked', function () {
    putInCart('Huevos', 'Patatas');
    $token = (string) Str::uuid();
    $eggsLine = cartLine('Huevos')->id;
    $eggs = Ingredient::where('name', 'Huevos')->sole();

    // 12 eggs instead of 8, and the two bags as suggested.
    saveChecked(['Huevos' => ['12', 'piece', 'fridge'], 'Patatas' => ['2', 'bag', 'pantry']], $token)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Guardado en casa: 2 productos.');

    expect(InventoryItem::query()->with('ingredient')->get()->mapWithKeys(fn (InventoryItem $row) => [$row->ingredient->name => $row->toQuantity()->format().' '.$row->location->value])->all())
        ->toBe(['Huevos' => '12 unidades fridge', 'Patatas' => '2 bolsas pantry'])
        ->and(Reception::sole()->items()->count())->toBe(2)
        ->and(StockEvent::query()->where('kind', 'add')->whereNotNull('reception_item_id')->count())->toBe(2);

    // Nothing left to buy of them: they leave the list (and stay in its history); rice stays.
    $lines = cartListLines();
    expect($lines)->not->toHaveKeys(['Huevos', 'Patatas'])
        ->and($lines['Arroz'])->toMatchArray(['is_received' => false, 'is_purchased' => false, 'display' => '300 g']);

    // The same sheet sent again adds nothing.
    $this->post(route('shopping-lists.receptions.checked', $this->list), [
        'token' => $token, 'lines' => [['item_id' => $eggsLine, 'quantity' => '12', 'unit' => 'piece', 'location' => 'fridge']],
    ])->assertInertiaFlash('toast.type', 'info');
    expect(Reception::count())->toBe(1)
        ->and($eggs->inventoryItems()->sole()->quantity)->toBe('12.000');
});

test('a partial purchase leaves the rest pending and out of the cart', function () {
    putInCart('Huevos');
    saveChecked(['Huevos' => ['4', 'piece', 'fridge']])->assertSessionHasNoErrors();

    $line = cartListLines()['Huevos'];
    expect($line)->toMatchArray(['is_received' => false, 'is_purchased' => false, 'received' => '4 unidades', 'remaining' => '4 unidades'])
        ->and($line['suggested']['quantity'])->toBe('4');
});

test('grams received for a line bought in bags count against it (conversions)', function () {
    putInCart('Patatas');
    saveChecked(['Patatas' => ['1', 'kg', 'pantry']])->assertSessionHasNoErrors();

    expect(cartListLines())->not->toHaveKey('Patatas')
        ->and(InventoryItem::sole()->toQuantity()->format())->toBe('1 kg');
});

test('two members at once: the second one is told, and nothing is added twice', function () {
    putInCart('Huevos', 'Patatas');
    $other = User::factory()->memberOf($this->household, HouseholdRole::Editor)->create();

    saveChecked(['Huevos' => ['8', 'piece', 'fridge']])->assertSessionHasNoErrors();

    // The other member's sheet still had the eggs: they are skipped, the potatoes are saved.
    $this->actingAs($other);
    saveChecked(['Huevos' => ['8', 'piece', 'fridge'], 'Patatas' => ['2', 'bag', 'pantry']])
        ->assertInertiaFlash('toast.message', 'Guardado en casa: 1 producto. Ya lo había guardado otra persona: «Huevos».');

    // Only eggs again: nothing left to save.
    saveChecked(['Huevos' => ['8', 'piece', 'fridge']])->assertSessionHasErrors('lines');

    expect(InventoryItem::query()->where('ingredient_id', cartLine('Huevos')->ingredient_id)->sole()->quantity)->toBe('8.000')
        ->and(Reception::count())->toBe(2);
});

test('free text never goes to the stock; an empty sheet is refused', function () {
    putInCart('Papel de cocina');
    saveChecked(['Papel de cocina' => ['1', 'pack', 'pantry']])->assertSessionHasErrors('lines.0.item_id');
    $this->post(route('shopping-lists.receptions.checked', $this->list), ['token' => (string) Str::uuid(), 'lines' => []])->assertSessionHasErrors('lines');

    expect(InventoryItem::count())->toBe(0)->and(Reception::count())->toBe(0);
});

test('viewers cannot save, and other households cannot reach the list', function () {
    putInCart('Huevos');
    $viewer = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create();
    $this->actingAs($viewer);
    saveChecked(['Huevos' => ['8', 'piece', 'fridge']])->assertForbidden();

    $this->actingAs(User::factory()->withHousehold()->create());
    $this->post(route('shopping-lists.receptions.checked', $this->list), [
        'token' => (string) Str::uuid(), 'lines' => [['item_id' => cartLine('Huevos')->id, 'quantity' => '8', 'unit' => 'piece', 'location' => 'fridge']],
    ])->assertNotFound();

    expect(Reception::count())->toBe(0);
});

test('the purchase is part of the history of the list', function () {
    putInCart('Huevos');
    saveChecked(['Huevos' => ['8', 'piece', 'fridge']]);

    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page
        ->has('receptions', 1)
        ->where('receptions.0.items.0.display', '8 unidades'));
});

test('race: another member saves the eggs while this request is saving; they are added once', function () {
    putInCart('Huevos', 'Patatas');
    $eggs = Ingredient::where('name', 'Huevos')->sole();
    $other = User::factory()->memberOf($this->household, HouseholdRole::Editor)->create();
    $done = false;

    // The other request finishes right after this one took the lock of the list.
    DB::listen(function (QueryExecuted $query) use (&$done, $other) {
        if (! $done && preg_match('/from `shopping_lists`.*for update/', $query->sql) === 1) {
            $done = true;
            app(ReceptionRecorder::class)->confirm($other->currentHousehold(), $this->list->fresh(), (string) Str::uuid(), [
                cartLine('Huevos')->id => ['outcome' => 'received', 'ingredient' => null, 'quantity' => Quantity::of('8', Unit::Piece), 'location' => StorageLocation::Fridge],
            ], [], partial: true);
        }
    });

    saveChecked(['Huevos' => ['8', 'piece', 'fridge'], 'Patatas' => ['2', 'bag', 'pantry']])
        ->assertInertiaFlash('toast.message', 'Guardado en casa: 1 producto. Ya lo había guardado otra persona: «Huevos».');

    expect($eggs->inventoryItems()->sole()->quantity)->toBe('8.000')
        ->and(StockEvent::query()->where('ingredient_id', $eggs->id)->count())->toBe(1)
        ->and(Reception::count())->toBe(2);
});
