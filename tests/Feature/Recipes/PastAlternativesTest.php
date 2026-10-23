<?php

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;

/**
 * RN-42 with RN-44 (Phase A): a past meal consumes the option chosen for it or, if none, the
 * one the estimate at its reference time proposes. Nothing is stored for proposals.
 */
beforeEach(function () {
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
    $this->nata = Ingredient::factory()->for($this->household)->named('Nata')->create();
    $this->leche = Ingredient::factory()->for($this->household)->named('Leche evaporada')->create();
    $this->post(route('recipes.store'), ['name' => 'Salsa', 'base_servings' => 1, 'ingredients' => [
        ['ingredient' => 'Nata', 'quantity' => '200', 'unit' => 'ml'],
        ['ingredient' => 'Leche evaporada', 'quantity' => '150', 'unit' => 'ml', 'alternative' => true],
    ]])->assertSessionHasNoErrors();
    $this->salsa = Recipe::where('name', 'Salsa')->sole();
});

function salsaOn(int $day): int
{
    test()->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => $day, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => test()->salsa->id, 'servings' => 1]);

    return dishIn(['week' => '2026-10-19', 'day' => $day, 'meal' => 'lunch']);
}

test('without a choice, the option the stock covered at that moment is consumed', function () {
    $this->post(route('inventory.store'), ['ingredient' => 'Leche evaporada', 'quantity' => '170', 'unit' => 'ml', 'location' => 'pantry']);
    $this->post(route('inventory.store'), ['ingredient' => 'Nata', 'quantity' => '500', 'unit' => 'ml', 'location' => 'fridge']);
    salsaOn(1);
    salsaOn(2);
    atMadrid('2026-10-22 10:00');

    // Cream is the first option and the stock covers it both days: 500 − 200 − 200.
    expect(estimateOf($this->nata)['total'])->toBe('100')
        ->and(estimateOf($this->leche)['total'])->toBe('170');
});

test('the proposal depends on the estimate of its moment: once cream runs out, the milk is used', function () {
    $this->post(route('inventory.store'), ['ingredient' => 'Nata', 'quantity' => '200', 'unit' => 'ml', 'location' => 'fridge']);
    $this->post(route('inventory.store'), ['ingredient' => 'Leche evaporada', 'quantity' => '170', 'unit' => 'ml', 'location' => 'pantry']);
    salsaOn(1);
    salsaOn(2);
    atMadrid('2026-10-22 10:00');

    // Monday takes the cream; on Tuesday it is gone and the milk is covered: 170 − 150.
    expect(estimateOf($this->nata)['total'])->toBe('0')
        ->and(estimateOf($this->leche)['total'])->toBe('20');
});

test('a choice is always respected, and a later count does not move past consumption', function () {
    $this->post(route('inventory.store'), ['ingredient' => 'Nata', 'quantity' => '500', 'unit' => 'ml', 'location' => 'fridge']);
    $this->post(route('inventory.store'), ['ingredient' => 'Leche evaporada', 'quantity' => '170', 'unit' => 'ml', 'location' => 'pantry']);
    $dish = salsaOn(1);
    $this->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $this->salsa->id, 'group' => 1, 'ingredient_id' => $this->leche->id]);
    atMadrid('2026-10-21 10:00');

    expect(estimateOf($this->leche)['total'])->toBe('20')
        ->and(estimateOf($this->nata)['total'])->toBe('500');

    // A count of the cream later changes nothing of Monday.
    $row = $this->nata->inventoryItems()->sole();
    $this->patch(route('inventory.adjust', $row), ['quantity' => '450']);
    expect(estimateOf($this->leche)['total'])->toBe('20')
        ->and(estimateOf($this->nata)['total'])->toBe('450');
});
