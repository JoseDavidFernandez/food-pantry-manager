<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\Quantity;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->actingAs($this->user);
    $household = $this->user->currentHousehold();

    $this->huevos = InventoryItem::factory()->for(Ingredient::factory()->for($household)->named('Huevos'))
        ->of('6', Unit::Piece, StorageLocation::Fridge)->create(['label' => 'camperos']);
    $this->arroz = InventoryItem::factory()->for(Ingredient::factory()->for($household)->named('Arroz'))
        ->of('1', Unit::Kilogram, StorageLocation::Pantry)->create();
});

test('indivisible units go one up or down, keeping unit, location and label', function () {
    $this->patch(route('inventory.adjust', $this->huevos), ['step' => 'down'])->assertSessionHasNoErrors();
    $this->patch(route('inventory.adjust', $this->huevos), ['step' => 'down'])->assertSessionHasNoErrors();
    $this->patch(route('inventory.adjust', $this->huevos), ['step' => 'up'])
        ->assertInertiaFlash('toast.message', 'Huevos: ahora hay 5 unidades.');

    $row = $this->huevos->fresh();
    expect($row->toQuantity()->format())->toBe('5 unidades')
        ->and($row->location)->toBe(StorageLocation::Fridge)
        ->and($row->label)->toBe('camperos');
});

test('a quick step never leaves zero or negative stock (RN-07)', function () {
    $this->huevos->setQuantity(Quantity::of('1', Unit::Piece));
    $this->huevos->save();

    $this->patch(route('inventory.adjust', $this->huevos), ['step' => 'down'])->assertSessionHasErrors('quantity');
    expect($this->huevos->fresh()->toQuantity()->format())->toBe('1 unidad');
});

test('a new amount is typed in the row unit, with the decimal comma', function () {
    $this->patch(route('inventory.adjust', $this->arroz), ['quantity' => '0,35'])->assertSessionHasNoErrors();

    expect($this->arroz->fresh()->toQuantity()->format())->toBe('0,35 kg');

    $this->patch(route('inventory.adjust', $this->arroz), ['quantity' => '0'])->assertSessionHasErrors('quantity');
    $this->patch(route('inventory.adjust', $this->arroz), [])->assertSessionHasErrors('quantity');
});

test('grams and millilitres cannot use the ± steps', function () {
    $this->patch(route('inventory.adjust', $this->arroz), ['step' => 'up'])->assertSessionHasErrors('quantity');

    expect($this->arroz->fresh()->toQuantity()->format())->toBe('1 kg');
});

test('the inventory page tells each row whether it can step', function () {
    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('items', fn ($items) => collect($items)->mapWithKeys(fn ($i) => [$i['ingredient'] => [$i['can_step'], $i['can_decrease']]])->all() === [
            'Arroz' => [false, false],
            'Huevos' => [true, true],
        ]));
});

test('another household stock cannot be adjusted', function () {
    $intruder = User::factory()->withHousehold()->create();
    $this->actingAs($intruder);

    $this->patch(route('inventory.adjust', $this->huevos), ['step' => 'up'])->assertForbidden();
    expect($this->huevos->fresh()->toQuantity()->format())->toBe('6 unidades');
});
