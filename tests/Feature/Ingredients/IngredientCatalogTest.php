<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
});

test('the inventory lists the whole catalog of the household, with aliases', function () {
    $tomate = Ingredient::factory()->for($this->household)->named('Tomate')->create();
    $tomate->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Tomates', 'normalized_alias' => 'tomates']);
    Ingredient::factory()->named('Arroz')->create(); // another household

    $this->get(route('inventory.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/Index')
            ->has('foods', 1)
            ->where('foods.0.name', 'Tomate')
            ->where('foods.0.aliases.0.alias', 'Tomates'));
});

test('an ingredient can be created with an optional default unit', function () {
    $this->post(route('ingredients.store'), ['name' => '  Aceite   de oliva ', 'default_unit' => 'l'])->assertRedirect();

    $ingredient = $this->household->ingredients()->sole();
    expect($ingredient->name)->toBe('Aceite de oliva')
        ->and($ingredient->normalized_name)->toBe('aceite de oliva')
        ->and($ingredient->default_unit)->toBe(Unit::Litre);
});

test('CA-03: "Tomate" is rejected when "tomate " exists; "Tomate pera" is a different ingredient', function () {
    Ingredient::factory()->for($this->household)->named('tomate ')->create();

    $this->post(route('ingredients.store'), ['name' => 'Tomate'])->assertSessionHasErrors('name');
    $this->post(route('ingredients.store'), ['name' => 'TÓMATE'])->assertSessionHasErrors('name');

    // Similar to "tomate": created as a distinct ingredient once the user confirms.
    $this->post(route('ingredients.store'), ['name' => 'Tomate pera'])->assertSessionHasErrors('name');
    $this->post(route('ingredients.store'), ['name' => 'Tomate pera', 'confirm_new' => '1'])->assertSessionHasNoErrors();

    expect($this->household->ingredients()->count())->toBe(2);
});

test('deleting stock keeps the ingredient in the catalog', function () {
    $arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $item = InventoryItem::factory()->for($arroz)->create(['household_id' => $this->household->id]);

    $this->delete(route('inventory.destroy', $item))->assertRedirect();

    expect(Ingredient::whereKey($arroz->id)->exists())->toBeTrue();
    $this->get(route('inventory.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('foods.0.name', 'Arroz')
            ->where('foods.0.stock_count', 0)
            ->where('counts.none', 1));
});

test('deleting an ingredient without stock also deletes its aliases; with stock it is refused', function () {
    $arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $arroz->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Arroz redondo', 'normalized_alias' => 'arroz redondo']);
    $item = InventoryItem::factory()->for($arroz)->create(['household_id' => $this->household->id]);

    $this->delete(route('ingredients.destroy', $arroz))->assertRedirect()->assertInertiaFlash('toast.type', 'error');
    expect(Ingredient::whereKey($arroz->id)->exists())->toBeTrue();

    $item->delete();
    $this->delete(route('ingredients.destroy', $arroz))->assertRedirect()->assertInertiaFlash('toast.type', 'success');

    expect(Ingredient::whereKey($arroz->id)->exists())->toBeFalse()
        ->and($this->household->ingredientAliases()->count())->toBe(0);
});

test('RN-04: an alias cannot match another ingredient name or alias', function () {
    $tomate = Ingredient::factory()->for($this->household)->named('Tomate')->create();
    Ingredient::factory()->for($this->household)->named('Cebolla')->create();

    $this->post(route('ingredients.aliases.store', $tomate), ['alias' => 'Tomates'])->assertSessionHasNoErrors();
    $this->post(route('ingredients.aliases.store', $tomate), ['alias' => 'tomates'])->assertSessionHasErrors('alias');
    $this->post(route('ingredients.aliases.store', $tomate), ['alias' => 'Cebolla'])->assertSessionHasErrors('alias');
    $this->post(route('ingredients.store'), ['name' => 'Tomates'])->assertSessionHasErrors('name');

    expect($tomate->aliases()->pluck('alias')->all())->toBe(['Tomates']);
});

test('an ingredient can be renamed, keeping its own name free', function () {
    $tomate = Ingredient::factory()->for($this->household)->named('Tomate')->create();

    $this->put(route('ingredients.update', $tomate), ['name' => 'tomate', 'default_unit' => 'piece'])->assertSessionHasNoErrors();
    $this->put(route('ingredients.update', $tomate), ['name' => 'Tomate rama'])->assertSessionHasNoErrors();

    expect($tomate->fresh()->name)->toBe('Tomate rama');
});

test('an alias can be removed', function () {
    $tomate = Ingredient::factory()->for($this->household)->named('Tomate')->create();
    $alias = $tomate->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Tomates', 'normalized_alias' => 'tomates']);

    $this->delete(route('ingredients.aliases.destroy', [$tomate, $alias]))->assertRedirect();

    expect($tomate->aliases()->count())->toBe(0);
});

test('an ingredient with stock cannot be deleted; without stock it can', function () {
    $arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    InventoryItem::factory()->for($arroz)->create(['household_id' => $this->household->id]);
    $sal = Ingredient::factory()->for($this->household)->named('Sal')->create();

    $this->delete(route('ingredients.destroy', $arroz))->assertRedirect();
    $this->delete(route('ingredients.destroy', $sal))->assertRedirect();

    expect(Ingredient::whereKey($arroz->id)->exists())->toBeTrue()
        ->and(Ingredient::whereKey($sal->id)->exists())->toBeFalse();
});

test('the old «Ingredientes» page redirects to Inventory, where the catalog is managed', function () {
    $this->get(route('ingredients.index'))->assertRedirect(route('inventory.index'));
});

test('the «Sin existencias» tab lists the catalog foods without stock and no stock rows', function () {
    $arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    Ingredient::factory()->for($this->household)->named('Sal')->create();
    InventoryItem::factory()->for($arroz)->create(['household_id' => $this->household->id]);

    $this->get(route('inventory.index', ['location' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filter', 'none')
            ->has('items', 0)
            ->has('foods', 2)
            ->where('counts.all', 1)
            ->where('counts.none', 1));
});
