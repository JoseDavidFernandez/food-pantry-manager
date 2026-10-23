<?php

use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * CA-02 / RN-02: a user can neither read nor modify resources of another household.
 */

beforeEach(function () {
    $this->owner = User::factory()->withHousehold()->create();
    $this->ownerHousehold = $this->owner->currentHousehold();

    $this->ingredient = Ingredient::factory()->for($this->ownerHousehold)->named('Arroz')->create();
    $this->alias = $this->ingredient->aliases()->create(['household_id' => $this->ownerHousehold->id, 'alias' => 'Arroz largo', 'normalized_alias' => 'arroz largo']);
    $this->item = InventoryItem::factory()->for($this->ingredient)->create();

    $this->intruder = User::factory()->withHousehold()->create();
    $this->actingAs($this->intruder);
});

test('listings only show the user own household data', function () {
    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page->has('items', 0)->has('foods', 0)->has('suggestions', 0));
});

test('another household ingredient cannot be modified', function () {
    $this->put(route('ingredients.update', $this->ingredient), ['name' => 'Hackeado'])->assertForbidden();
    $this->delete(route('ingredients.destroy', $this->ingredient))->assertForbidden();
    $this->post(route('ingredients.aliases.store', $this->ingredient), ['alias' => 'Otro'])->assertForbidden();
    $this->delete(route('ingredients.aliases.destroy', [$this->ingredient, $this->alias]))->assertForbidden();

    expect($this->ingredient->fresh()->name)->toBe('Arroz')
        ->and($this->ingredient->aliases()->count())->toBe(1);
});

test('another household stock cannot be modified', function () {
    $this->put(route('inventory.update', $this->item), ['quantity' => '1', 'unit' => 'g', 'location' => 'pantry'])->assertForbidden();
    $this->delete(route('inventory.destroy', $this->item))->assertForbidden();

    expect($this->item->fresh()->quantity)->toBe('500.000');
});

test('typing another household ingredient name does not resolve to it', function () {
    $this->post(route('inventory.store'), ['ingredient' => 'Arroz', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry'])
        ->assertSessionHasErrors('ingredient');

    $this->post(route('inventory.store'), ['ingredient' => 'Arroz', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry', 'create_ingredient' => '1'])
        ->assertSessionHasNoErrors();

    $mine = $this->intruder->currentHousehold();
    expect($mine->ingredients()->sole()->id)->not->toBe($this->ingredient->id)
        ->and($this->ownerHousehold->inventoryItems()->count())->toBe(1);
});

test('an alias cannot be removed through another ingredient (scoped binding)', function () {
    $mine = Ingredient::factory()->for($this->intruder->currentHousehold())->named('Sal')->create();

    $this->delete(route('ingredients.aliases.destroy', [$mine, $this->alias]))->assertNotFound();
    expect($this->ingredient->aliases()->count())->toBe(1);
});

test('the household policies deny guests', function () {
    auth()->logout();

    $this->get(route('inventory.index'))->assertRedirect(route('login'));
    $this->get(route('ingredients.index'))->assertRedirect(route('login'));
});
