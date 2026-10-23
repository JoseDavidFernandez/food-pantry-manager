<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Reception;
use App\Models\ReceptionItem;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const RECEPTION_WEEK = '2026-10-19';

const RECEPTION_END = '2026-10-25';

beforeEach(function () {
    // Monday 19 Oct 2026 in Madrid: the list of the week is the current one (RN-36).
    $this->travelTo('2026-10-19 08:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $this->tomate = Ingredient::factory()->for($this->household)->named('Tomate frito')->create();
    $this->leche = Ingredient::factory()->for($this->household)->named('Leche')->create();
});

/**
 * Plan a dish with these ingredients on Monday lunch and open the list of the week (RN-36).
 *
 * @param  list<array{ingredient: string, quantity: string, unit: string}>  $ingredients
 */
function listFor(array $ingredients): void
{
    planInSlot(['week' => RECEPTION_WEEK, 'day' => 1, 'meal' => 'lunch'], [
        'type' => 'adhoc', 'name' => 'Plato', 'servings' => 2, 'ingredients' => $ingredients,
    ])->assertSessionHasNoErrors();

    openList()->assertOk();
}

function openList(): mixed
{
    return test()->get(route('shopping-list.period', ['start' => RECEPTION_WEEK, 'end' => RECEPTION_END]));
}

function receptionListId(): int
{
    return (int) auth()->user()->currentHousehold()->shoppingLists()->whereDate('period_start', RECEPTION_WEEK)->value('id');
}

function line(string $name): ShoppingListItem
{
    return ShoppingListItem::query()->where('name', $name)->firstOrFail();
}

function received(string $name, string $quantity, string $unit, string $location = 'pantry'): array
{
    return ['item_id' => line($name)->id, 'outcome' => 'received', 'quantity' => $quantity, 'unit' => $unit, 'location' => $location];
}

function notPurchased(string $name): array
{
    return ['item_id' => line($name)->id, 'outcome' => 'not_purchased'];
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  list<array<string, mixed>>  $extras
 */
function confirmReception(array $lines, array $extras = [], ?string $token = null): mixed
{
    return test()->post(route('shopping-lists.receptions.store', receptionListId()), [
        'token' => $token ?? (string) Str::uuid(),
        'lines' => $lines,
        'extras' => $extras,
    ]);
}

/**
 * Pending lines offered by the reception form, keyed by name.
 *
 * @return array<string, array<string, mixed>>
 */
function receptionLines(): array
{
    $lines = [];

    test()->get(route('shopping-lists.receptions.create', receptionListId()))
        ->assertInertia(function (Assert $page) use (&$lines) {
            $page->component('receptions/Create');
            $lines = collect($page->toArray()['props']['lines'])->keyBy('name')->all();
        });

    return $lines;
}

/**
 * @return list<string>
 */
function stockOf(Ingredient $ingredient): array
{
    return InventoryItem::query()->where('ingredient_id', $ingredient->id)->orderBy('id')->get()
        ->map(fn (InventoryItem $row) => $row->location->value.' '.$row->toQuantity()->format())
        ->all();
}

test('the reception form offers every pending line with an editable suggestion and a token', function () {
    InventoryItem::factory()->for($this->arroz)->of('500', Unit::Gram, StorageLocation::Pantry)->create();
    listFor([
        ['ingredient' => 'Arroz', 'quantity' => '900', 'unit' => 'g'],
        ['ingredient' => 'Tomate frito', 'quantity' => '1,5', 'unit' => 'jar'],
    ]);

    $this->get(route('shopping-lists.receptions.create', receptionListId()))
        ->assertInertia(fn (Assert $page) => $page
            ->component('receptions/Create')
            ->where('receptionCount', 0)
            ->where('token', fn (string $token) => Str::isUuid($token))
            ->has('lines', 2)
            ->where('lines.0.name', 'Arroz')
            ->where('lines.0.display', '400 g')
            ->where('lines.0.location', 'pantry')
            ->where('lines.0.stock.0.display', '500 g')
            ->where('lines.1.display', '2 botes')
            ->where('lines.1.location', null));
});

test('CA-14: received quantities (also more than planned) and unforeseen products are added exactly', function () {
    InventoryItem::factory()->for($this->arroz)->of('500', Unit::Gram, StorageLocation::Pantry)->create();
    listFor([
        ['ingredient' => 'Arroz', 'quantity' => '1,5', 'unit' => 'kg'],
        ['ingredient' => 'Tomate frito', 'quantity' => '2', 'unit' => 'jar'],
        ['ingredient' => 'Leche', 'quantity' => '1', 'unit' => 'l'],
    ]);

    confirmReception(
        [
            received('Arroz', '1', 'kg'),
            received('Tomate frito', '3', 'jar'),
            notPurchased('Leche'),
        ],
        [['ingredient' => 'Huevos', 'quantity' => '12', 'unit' => 'piece', 'location' => 'fridge', 'create_ingredient' => '1']],
    )->assertSessionHasNoErrors()->assertRedirect(route('shopping-list.period', ['start' => RECEPTION_WEEK, 'end' => RECEPTION_END]))
        ->assertInertiaFlash('toast.message', 'Compra registrada: 3 productos añadidos al inventario.');

    // 500 g + 1 kg in the same location → one row of 1.5 kg (RN-06).
    expect(stockOf($this->arroz))->toBe(['pantry 1,5 kg'])
        ->and(stockOf($this->tomate))->toBe(['pantry 3 botes'])
        // RN-30: «No comprado» does not change the stock.
        ->and(stockOf($this->leche))->toBe([])
        ->and(stockOf(Ingredient::where('name', 'Huevos')->sole()))->toBe(['fridge 12 unidades']);

    $reception = Reception::sole();
    expect($reception->items)->toHaveCount(4)
        ->and($reception->items->firstWhere('name', 'Tomate frito')->suggested()->format())->toBe('2 botes')
        ->and($reception->items->firstWhere('name', 'Leche')->outcome)->toBe(ReceptionItem::NOT_PURCHASED)
        ->and($reception->items->firstWhere('name', 'Huevos')->is_unforeseen)->toBeTrue();
});

test('CA-14: the reception cannot be confirmed with undecided lines', function () {
    listFor([
        ['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g'],
        ['ingredient' => 'Leche', 'quantity' => '1', 'unit' => 'l'],
    ]);

    // A line left out of the form.
    confirmReception([received('Arroz', '600', 'g')])
        ->assertSessionHasErrors(['lines' => 'Queda 1 línea sin decidir: «Leche».']);

    // A line sent without a decision.
    confirmReception([received('Arroz', '600', 'g'), ['item_id' => line('Leche')->id]])
        ->assertSessionHasErrors('lines.1.outcome');

    expect(Reception::count())->toBe(0)
        ->and(stockOf($this->arroz))->toBe([]);
});

test('CA-14: resubmitting the same confirmation does not add the stock again', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $token = (string) Str::uuid();

    confirmReception([received('Arroz', '600', 'g')], token: $token)->assertSessionHasNoErrors();
    confirmReception([received('Arroz', '600', 'g')], token: $token)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'info');

    expect(Reception::count())->toBe(1)
        ->and(ReceptionItem::count())->toBe(1)
        ->and(stockOf($this->arroz))->toBe(['pantry 600 g']);
});

test('a purchase can be received in several partial receptions without losing what is pending', function () {
    listFor([
        ['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g'],
        ['ingredient' => 'Tomate frito', 'quantity' => '3', 'unit' => 'jar'],
        ['ingredient' => 'Leche', 'quantity' => '2', 'unit' => 'l'],
    ]);

    // First shop: part of the rice, one jar, no milk.
    confirmReception([
        received('Arroz', '0,4', 'kg'),
        received('Tomate frito', '1', 'jar'),
        notPurchased('Leche'),
    ])->assertSessionHasNoErrors();

    expect(stockOf($this->arroz))->toBe(['pantry 0,4 kg'])
        ->and(stockOf($this->tomate))->toBe(['pantry 1 bote']);

    // Only what is still pending is suggested: the list asks for what is missing with the new
    // stock (RN-36), and shows what already arrived for the line.
    $pending = receptionLines();
    expect($pending)->toHaveCount(3)
        ->and($pending['Arroz']['display'])->toBe('200 g')
        ->and($pending['Arroz']['received'])->toBe('400 g')
        ->and($pending['Tomate frito']['display'])->toBe('2 botes')
        ->and($pending['Leche']['display'])->toBe('2 l');

    // Second shop: the rest of the rice and the milk; still no tomato.
    confirmReception([
        received('Arroz', '200', 'g'),
        notPurchased('Tomate frito'),
        received('Leche', '2', 'l', 'fridge'),
    ])->assertSessionHasNoErrors();

    expect(stockOf($this->arroz))->toBe(['pantry 600 g'])
        ->and(stockOf($this->leche))->toBe(['fridge 2 l'])
        ->and(array_keys(receptionLines()))->toBe(['Tomate frito']);

    // Third shop: the two remaining jars complete the list.
    confirmReception([received('Tomate frito', '2', 'jar')])->assertSessionHasNoErrors();

    expect(receptionLines())->toBe([])
        ->and(stockOf($this->tomate))->toBe(['pantry 3 botes']);

    // Each reception keeps only what arrived on that occasion.
    $history = Reception::query()->orderBy('id')->with('items')->get()
        ->map(fn (Reception $r) => $r->items->sortBy('id')->values()->map(fn (ReceptionItem $i) => $i->name.': '.($i->received()?->format() ?? 'no comprado'))->all())
        ->all();

    expect($history)->toBe([
        ['Arroz: 0,4 kg', 'Leche: no comprado', 'Tomate frito: 1 bote'],
        ['Arroz: 200 g', 'Leche: 2 l', 'Tomate frito: no comprado'],
        ['Tomate frito: 2 botes'],
    ]);
});

test('the list page shows what is still needed after a reception, and the reception itself', function () {
    listFor([
        ['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g'],
        ['ingredient' => 'Leche', 'quantity' => '1', 'unit' => 'l'],
    ]);
    confirmReception([received('Arroz', '250', 'g'), received('Leche', '1,5', 'l', 'fridge')]);

    openList()
        ->assertInertia(fn (Assert $page) => $page
            // 600 g needed, 250 g at home now: 350 g still to buy. The milk is complete.
            ->has('items', 1)
            ->where('items.0.name', 'Arroz')
            ->where('items.0.display', '350 g')
            ->where('items.0.received', '250 g')
            ->where('items.0.remaining', '350 g')
            ->where('items.0.is_received', false)
            ->has('receptions', 1)
            ->where('receptions.0.items.0.display', '250 g')
            ->where('receptions.0.items.1.display', '1,5 l')
            ->where('receptions.0.items.1.location', 'Frigorífico'));
});

test('an edited quantity below the suggestion leaves the rest pending; above it completes the line', function () {
    listFor([
        ['ingredient' => 'Arroz', 'quantity' => '1', 'unit' => 'kg'],
        ['ingredient' => 'Tomate frito', 'quantity' => '2', 'unit' => 'jar'],
    ]);

    confirmReception([received('Arroz', '300', 'g'), received('Tomate frito', '5', 'jar')]);

    expect(array_keys(receptionLines()))->toBe(['Arroz'])
        ->and(receptionLines()['Arroz']['display'])->toBe('700 g')
        ->and(stockOf($this->tomate))->toBe(['pantry 5 botes']);
});

test('an incompatible unit creates a separate stock row and does not reduce what is pending', function () {
    InventoryItem::factory()->for($this->tomate)->of('100', Unit::Gram, StorageLocation::Pantry)->create();
    listFor([['ingredient' => 'Tomate frito', 'quantity' => '400', 'unit' => 'g']]);

    confirmReception([received('Tomate frito', '1', 'jar')])->assertSessionHasNoErrors();

    // RN-08: jars and grams never merge.
    expect(stockOf($this->tomate))->toBe(['pantry 100 g', 'pantry 1 bote']);

    // The jar is not subtracted from the grams still needed (RN-22).
    $pending = receptionLines()['Tomate frito'];
    expect($pending['display'])->toBe('300 g');
});

test('a different location creates its own row; the same location merges', function () {
    InventoryItem::factory()->for($this->leche)->of('1', Unit::Litre, StorageLocation::Fridge)->create();
    listFor([['ingredient' => 'Leche', 'quantity' => '3', 'unit' => 'l']]);

    confirmReception([received('Leche', '500', 'ml', 'fridge')]);
    confirmReception([received('Leche', '2,5', 'l', 'pantry')]);

    expect(stockOf($this->leche))->toBe(['fridge 1,5 l', 'pantry 2,5 l']);
});

test('unforeseen products need an explicit ingredient: existing, by alias or created on purpose', function () {
    $this->arroz->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Arroz redondo', 'normalized_alias' => 'arroz redondo']);
    listFor([['ingredient' => 'Leche', 'quantity' => '1', 'unit' => 'l']]);

    // Unknown and not marked to be created.
    confirmReception([notPurchased('Leche')], [['ingredient' => 'Galletas', 'quantity' => '1', 'unit' => 'pack', 'location' => 'pantry']])
        ->assertSessionHasErrors('extras.0.ingredient');

    // Looks like an existing ingredient: needs the confirmation that it is a new one.
    confirmReception([notPurchased('Leche')], [['ingredient' => 'Tomate frit', 'quantity' => '1', 'unit' => 'jar', 'location' => 'pantry', 'create_ingredient' => '1']])
        ->assertSessionHasErrors('extras.0.ingredient');

    // Missing location.
    confirmReception([notPurchased('Leche')], [['ingredient' => 'Arroz', 'quantity' => '1', 'unit' => 'kg']])
        ->assertSessionHasErrors('extras.0.location');

    expect(Reception::count())->toBe(0)->and(Ingredient::where('name', 'Galletas')->exists())->toBeFalse();

    confirmReception([notPurchased('Leche')], [
        ['ingredient' => 'arroz redondo', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry'],
        ['ingredient' => 'Galletas', 'quantity' => '2', 'unit' => 'pack', 'location' => 'pantry', 'create_ingredient' => '1'],
        ['ingredient' => '', 'quantity' => ''],
    ])->assertSessionHasNoErrors();

    expect(stockOf($this->arroz))->toBe(['pantry 1 kg'])
        ->and(stockOf(Ingredient::where('name', 'Galletas')->sole()))->toBe(['pantry 2 paquetes'])
        ->and(ReceptionItem::where('is_unforeseen', true)->count())->toBe(2);
});

test('a free-text line goes to the stock as an ingredient chosen or created explicitly', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $this->post(route('shopping-lists.items.store', receptionListId()), ['item' => 'Papel de cocina'])->assertSessionHasNoErrors();

    $paper = ['item_id' => line('Papel de cocina')->id, 'outcome' => 'received', 'quantity' => '2', 'unit' => 'pack', 'location' => 'pantry'];

    confirmReception([notPurchased('Arroz'), $paper])->assertSessionHasErrors('lines.1.ingredient');

    confirmReception([notPurchased('Arroz'), [...$paper, 'create_ingredient' => '1']])->assertSessionHasNoErrors();

    $ingredient = Ingredient::where('name', 'Papel de cocina')->sole();
    expect(stockOf($ingredient))->toBe(['pantry 2 paquetes'])
        // Free text without quantity is complete once received.
        ->and(array_keys(receptionLines()))->toBe(['Arroz']);
});

test('a reception needs at least one product received', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);

    confirmReception([notPurchased('Arroz')])->assertSessionHasErrors('lines');

    expect(Reception::count())->toBe(0);
});

test('a received line needs quantity, unit and location', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);

    confirmReception([['item_id' => line('Arroz')->id, 'outcome' => 'received']])
        ->assertSessionHasErrors(['lines.0.quantity', 'lines.0.unit', 'lines.0.location']);
    confirmReception([[...received('Arroz', '0', 'g')]])->assertSessionHasErrors('lines.0.quantity');
});

test('a stale form (a line already completed) is rejected and nothing is applied', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $this->post(route('shopping-lists.items.store', receptionListId()), ['item' => 'Leche', 'quantity' => '1', 'unit' => 'l']);

    $stale = [received('Arroz', '600', 'g'), notPurchased('Leche')];

    confirmReception($stale)->assertSessionHasNoErrors();
    confirmReception($stale)->assertSessionHasErrors(['lines' => 'La lista ha cambiado desde que abriste el formulario. Vuelve a cargar la recepción.']);

    expect(Reception::count())->toBe(1)
        ->and(stockOf($this->arroz))->toBe(['pantry 600 g']);
});

test('RN-25: lines marked as purchased are still pending until they are received; receiving does not change the mark', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $this->patch(route('shopping-lists.items.purchased', ['list' => receptionListId(), 'item' => line('Arroz')->id]));

    expect(receptionLines()['Arroz']['is_purchased'])->toBeTrue()
        ->and(stockOf($this->arroz))->toBe([]);

    confirmReception([received('Arroz', '600', 'g')]);

    expect(line('Arroz')->is_purchased)->toBeTrue()
        ->and(receptionLines())->toBe([]);
});

test('lines removed from the list are not part of the reception', function () {
    listFor([
        ['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g'],
        ['ingredient' => 'Leche', 'quantity' => '1', 'unit' => 'l'],
    ]);
    $this->delete(route('shopping-lists.items.destroy', ['list' => receptionListId(), 'item' => line('Leche')->id]));

    expect(array_keys(receptionLines()))->toBe(['Arroz']);
    confirmReception([received('Arroz', '600', 'g')])->assertSessionHasNoErrors();
});

test('the history survives when a received line is later removed from the list', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $this->post(route('shopping-lists.items.store', receptionListId()), ['item' => 'Leche', 'quantity' => '1', 'unit' => 'l']);
    confirmReception([received('Arroz', '600', 'g'), received('Leche', '1', 'l', 'fridge')]);

    $this->delete(route('shopping-lists.items.destroy', ['list' => receptionListId(), 'item' => line('Leche')->id]));

    $history = ReceptionItem::where('name', 'Leche')->sole();
    expect($history->shopping_list_item_id)->toBeNull()
        ->and($history->received()->format())->toBe('1 l');
});

test('an ingredient that appears in a reception cannot be deleted', function () {
    listFor([['ingredient' => 'Leche', 'quantity' => '1', 'unit' => 'l']]);
    confirmReception([notPurchased('Leche')], [['ingredient' => 'Galletas', 'quantity' => '1', 'unit' => 'pack', 'location' => 'pantry', 'create_ingredient' => '1']]);

    $galletas = Ingredient::where('name', 'Galletas')->sole();
    InventoryItem::where('ingredient_id', $galletas->id)->delete();

    $this->delete(route('ingredients.destroy', $galletas))->assertInertiaFlash('toast.type', 'error');
    expect($galletas->fresh())->not->toBeNull();
});

test('without a list there is no reception', function () {
    $this->get(route('shopping-lists.receptions.create', 999999))->assertNotFound();

    confirmReception([])->assertNotFound();
});

test('another household cannot see or receive this list, nor reuse its lines', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $item = line('Arroz');
    $listId = receptionListId();

    $intruder = User::factory()->withHousehold()->create();
    $this->actingAs($intruder);

    $this->get(route('shopping-lists.receptions.create', $listId))->assertNotFound();
    $this->post(route('shopping-lists.receptions.store', $listId), ['token' => (string) Str::uuid(), 'lines' => [], 'extras' => []])->assertNotFound();
    confirmReception([['item_id' => $item->id, 'outcome' => 'received', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry']])
        ->assertNotFound();

    // With a list of their own, lines of the other household are a stale form, never received.
    $rice = Ingredient::factory()->for($intruder->currentHousehold())->named('Arroz')->create();
    listFor([['ingredient' => 'Arroz', 'quantity' => '100', 'unit' => 'g']]);
    $own = ShoppingListItem::where('ingredient_id', $rice->id)->sole();

    confirmReception([
        ['item_id' => $own->id, 'outcome' => 'not_purchased'],
        ['item_id' => $item->id, 'outcome' => 'received', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry'],
    ])->assertSessionHasErrors('lines');

    expect(InventoryItem::count())->toBe(0)
        ->and(Reception::count())->toBe(0);
});

test('a token already used by another household is never applied', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $token = (string) Str::uuid();
    confirmReception([received('Arroz', '600', 'g')], token: $token);

    $intruder = User::factory()->withHousehold()->create();
    $this->actingAs($intruder);
    $rice = Ingredient::factory()->for($intruder->currentHousehold())->named('Arroz')->create();
    listFor([['ingredient' => 'Arroz', 'quantity' => '100', 'unit' => 'g']]);

    confirmReception([['item_id' => ShoppingListItem::where('ingredient_id', $rice->id)->sole()->id, 'outcome' => 'received', 'quantity' => '100', 'unit' => 'g', 'location' => 'pantry']], token: $token)
        ->assertNotFound();

    expect(stockOf($rice))->toBe([]);
});

/**
 * Opening the list again reconciles it with the new stock (RN-36), as closing again did.
 */
function reopenAndClose(): void
{
    openList()->assertOk();
}

test('after a partial reception, opening the list again does not subtract what was received twice', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    confirmReception([received('Arroz', '0,4', 'kg')]);

    reopenAndClose();

    // The recalculation already counts the 400 g in stock: 600 − 400 = 200 g still to buy.
    expect(line('Arroz')->toQuantity()->format())->toBe('200 g')
        ->and(receptionLines()['Arroz']['display'])->toBe('200 g');

    confirmReception([received('Arroz', '200', 'g')]);
    expect(receptionLines())->toBe([])
        ->and(stockOf($this->arroz))->toBe(['pantry 600 g']);
});

test('after a full reception, a bigger menu asks only for the new difference', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '1', 'unit' => 'kg']]);
    confirmReception([received('Arroz', '1', 'kg')]);
    expect(receptionLines())->toBe([]);

    listFor([['ingredient' => 'Arroz', 'quantity' => '1,5', 'unit' => 'kg']]);

    expect(receptionLines()['Arroz']['display'])->toBe('500 g');
});

test('a user-modified line still counts every reception against the user quantity', function () {
    listFor([['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]);
    $this->patch(route('shopping-lists.items.update', ['list' => receptionListId(), 'item' => line('Arroz')->id]), ['quantity' => '1', 'unit' => 'kg']);
    confirmReception([received('Arroz', '400', 'g')]);

    reopenAndClose();

    expect(line('Arroz')->toQuantity()->format())->toBe('1 kg')
        ->and(receptionLines()['Arroz']['display'])->toBe('600 g');
});

test('a reception can store what arrives in the freezer, merging per location', function () {
    InventoryItem::factory()->for($this->leche)->of('1', Unit::Litre, StorageLocation::Fridge)->create();
    listFor([['ingredient' => 'Leche', 'quantity' => '3', 'unit' => 'l']]);

    confirmReception([received('Leche', '2', 'l', 'freezer')])->assertSessionHasNoErrors();

    expect(stockOf($this->leche))->toBe(['fridge 1 l', 'freezer 2 l']);
});
