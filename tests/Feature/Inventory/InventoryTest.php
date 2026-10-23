<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
});

function addStock(array $data): TestResponse
{
    return test()->post(route('inventory.store'), [
        'location' => 'pantry',
        'unit' => 'g',
        ...$data,
    ]);
}

function stockRows(): array
{
    return InventoryItem::query()->orderBy('id')->get()
        ->map(fn (InventoryItem $item) => "{$item->location->value} {$item->quantity} {$item->unit->value}")
        ->all();
}

test('stock is added for an existing ingredient', function () {
    Ingredient::factory()->for($this->household)->named('Arroz')->create();

    addStock(['ingredient' => 'arroz', 'quantity' => '500', 'label' => 'Paquete abierto'])->assertSessionHasNoErrors();

    expect(stockRows())->toBe(['pantry 500.000 g'])
        ->and(InventoryItem::sole()->label)->toBe('Paquete abierto');
});

test('an unknown ingredient is only created when explicitly requested', function () {
    addStock(['ingredient' => 'Garbanzos', 'quantity' => '1', 'unit' => 'kg'])->assertSessionHasErrors('ingredient');
    expect(InventoryItem::count())->toBe(0);

    addStock(['ingredient' => 'Garbanzos', 'quantity' => '1', 'unit' => 'kg', 'create_ingredient' => '1'])->assertSessionHasNoErrors();
    expect($this->household->ingredients()->pluck('name')->all())->toBe(['Garbanzos'])
        ->and(stockRows())->toBe(['pantry 1.000 kg']);
});

test('CA-03: a typed alias resolves to its ingredient', function () {
    $tomate = Ingredient::factory()->for($this->household)->named('tomate')->create();
    $tomate->aliases()->create(['household_id' => $this->household->id, 'alias' => 'tomates', 'normalized_alias' => 'tomates']);

    addStock(['ingredient' => 'Tomates', 'quantity' => '3', 'unit' => 'piece', 'location' => 'fridge'])->assertSessionHasNoErrors();

    expect(InventoryItem::sole()->ingredient_id)->toBe($tomate->id)
        ->and($this->household->ingredients()->count())->toBe(1);
});

test('CA-04 / RN-08: compatible quantities share one row; incompatible ones stay separate', function () {
    Ingredient::factory()->for($this->household)->named('Tomate frito')->create();

    addStock(['ingredient' => 'Tomate frito', 'quantity' => '500', 'unit' => 'g']);
    addStock(['ingredient' => 'Tomate frito', 'quantity' => '1', 'unit' => 'kg']);
    addStock(['ingredient' => 'Tomate frito', 'quantity' => '1', 'unit' => 'jar']);
    addStock(['ingredient' => 'Tomate frito', 'quantity' => '200', 'unit' => 'g', 'location' => 'fridge']);

    expect(stockRows())->toBe(['pantry 1.500 kg', 'pantry 1.000 jar', 'fridge 200.000 g']);
});

test('the Spanish decimal comma is accepted', function () {
    Ingredient::factory()->for($this->household)->named('Leche')->create();

    addStock(['ingredient' => 'Leche', 'quantity' => '1,5', 'unit' => 'l', 'location' => 'fridge'])->assertSessionHasNoErrors();

    expect(stockRows())->toBe(['fridge 1.500 l']);
});

test('RN-07: quantities must be positive with at most three decimals', function (string $quantity) {
    Ingredient::factory()->for($this->household)->named('Arroz')->create();

    addStock(['ingredient' => 'Arroz', 'quantity' => $quantity])->assertSessionHasErrors('quantity');
})->with(['0', '-2', '1,2345', 'mucho']);

test('RN-07: the database rejects non-positive quantities too', function () {
    $item = InventoryItem::factory()->create();

    expect(fn () => $item->forceFill(['quantity' => '0'])->save())->toThrow(QueryException::class);
});

test('stock can be edited within its compatible unit group', function () {
    $item = InventoryItem::factory()->for(Ingredient::factory()->for($this->household)->named('Arroz'))
        ->of('500', Unit::Gram)->create();

    $this->put(route('inventory.update', $item), ['quantity' => '2', 'unit' => 'kg', 'location' => 'pantry', 'label' => 'Bolsa grande'])
        ->assertSessionHasNoErrors();

    expect(stockRows())->toBe(['pantry 2.000 kg'])
        ->and($item->fresh()->label)->toBe('Bolsa grande');
});

test('changing to an incompatible unit is rejected', function () {
    $item = InventoryItem::factory()->for(Ingredient::factory()->for($this->household)->named('Arroz'))
        ->of('500', Unit::Gram)->create();

    $this->put(route('inventory.update', $item), ['quantity' => '1', 'unit' => 'pack', 'location' => 'pantry'])
        ->assertSessionHasErrors('unit');

    expect(stockRows())->toBe(['pantry 500.000 g']);
});

test('moving stock to a location with a compatible row merges them', function () {
    $leche = Ingredient::factory()->for($this->household)->named('Leche')->create();
    $pantry = InventoryItem::factory()->for($leche)->of('1', Unit::Litre, StorageLocation::Pantry)->create();
    InventoryItem::factory()->for($leche)->of('500', Unit::Millilitre, StorageLocation::Fridge)->create();

    $this->put(route('inventory.update', $pantry), ['quantity' => '1', 'unit' => 'l', 'location' => 'fridge'])
        ->assertSessionHasNoErrors();

    expect(stockRows())->toBe(['fridge 1.500 l']);
});

test('stock can be deleted', function () {
    $item = InventoryItem::factory()->for(Ingredient::factory()->for($this->household))->create();

    $this->delete(route('inventory.destroy', $item))->assertRedirect();

    expect(InventoryItem::count())->toBe(0);
});

test('the inventory page filters by location and shows quantities the Spanish way', function () {
    $leche = Ingredient::factory()->for($this->household)->named('Leche')->create();
    InventoryItem::factory()->for($leche)->of('1.5', Unit::Litre, StorageLocation::Fridge)->create();
    InventoryItem::factory()->for(Ingredient::factory()->for($this->household)->named('Arroz'))->of('1', Unit::Pack)->create();

    $this->get(route('inventory.index', ['location' => 'fridge']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/Index')
            ->where('filter', 'fridge')
            ->where('counts', ['all' => 2, 'fridge' => 1, 'freezer' => 0, 'pantry' => 1, 'none' => 0])
            ->has('items', 1)
            ->where('items.0.ingredient', 'Leche')
            ->where('items.0.quantity', '1,5')
            ->where('items.0.unit_label', 'l'));

    $this->get(route('inventory.index'))
        ->assertInertia(fn (Assert $page) => $page->has('items', 2)->where('items.0.ingredient', 'Arroz')->where('items.0.unit_label', 'paquete'));
});

test('empty inventory renders without items', function () {
    $this->get(route('inventory.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('items', 0)->where('counts.all', 0));
});
