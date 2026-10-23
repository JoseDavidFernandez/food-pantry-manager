<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('the home page summarises the current week, its list, the stock and the recipes', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $user = User::factory()->withHousehold()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('week.key', '2026-10-19')
        ->where('menu', null)
        ->where('list.to_buy', 0)
        ->where('stockCount', 0)
        ->where('recipeCount', 0));

    Ingredient::factory()->for($user->currentHousehold())->named('Arroz')->create();
    planInSlot(['week' => '2026-10-19', 'day' => 3, 'meal' => 'lunch'], [
        'type' => 'adhoc', 'name' => 'Arroz', 'servings' => 2, 'ingredients' => [['ingredient' => 'Arroz', 'quantity' => '300', 'unit' => 'g']],
    ]);

    // No closing needed: the next 7 days are calculated (RN-36).
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('menu', ['planned' => 1, 'slots' => 14])
        ->where('list.to_buy', 1)
        ->where('list.to_review', 0));
});

test('the home page only counts the user household', function () {
    $other = User::factory()->withHousehold()->create();
    $this->actingAs($other);
    planInSlot(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'], ['type' => 'adhoc', 'name' => 'Pizza', 'servings' => 2]);

    $this->travelTo('2026-10-20 10:00:00');
    $this->actingAs(User::factory()->withHousehold()->create());

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('menu', null));
});

test('the welcome page is in Spanish', function () {
    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    expect(file_get_contents(resource_path('js/pages/Welcome.vue')))
        ->toContain('Iniciar sesión')
        ->not->toContain('Laravel');
});

test('CA-22: home shows today first, then the whole week, from the real menu (Europe/Madrid)', function () {
    // Wednesday 21 Oct 2026, 23:30 UTC is already Thursday 22 in Madrid.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:30:00', 'UTC'));
    $user = User::factory()->withHousehold()->create();
    $this->actingAs($user);
    $recipe = Recipe::factory()->for($user->currentHousehold())->create(['name' => 'Lentejas', 'base_servings' => 4]);

    planInSlot(['week' => '2026-10-19', 'day' => 4, 'meal' => 'dinner'], ['type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 2]);
    planInSlot(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'], ['type' => 'adhoc', 'name' => 'Tortilla', 'servings' => 3]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('today.day', 4)
        ->where('today.label', 'Jueves 22')
        ->where('today.is_today', true)
        ->where('today.lunch', [])
        ->where('today.dinner.0', ['name' => 'Lentejas', 'servings' => 2, 'type' => 'recipe', 'recipe_id' => $recipe->id, 'not_eaten' => false, 'complete' => false])
        ->has('days', 7)
        ->where('days.0.lunch.0.name', 'Tortilla')
        ->where('days.0.is_today', false)
        ->where('days.3.dinner.0.name', 'Lentejas')
        ->where('menu.planned', 2));
});

test('CA-22: home lists what to buy for the next 7 days with its quantity, from the real menu and stock', function () {
    $this->travelTo('2026-10-20 10:00:00');
    $user = User::factory()->withHousehold()->create();
    $this->actingAs($user);
    Ingredient::factory()->for($user->currentHousehold())->named('Arroz')->create();
    $huevos = Ingredient::factory()->for($user->currentHousehold())->named('Huevos')->create();
    InventoryItem::factory()->for($huevos)->of('6', Unit::Piece, StorageLocation::Fridge)->create();

    $dish = ['type' => 'adhoc', 'name' => 'Arroz con huevo', 'servings' => 2, 'ingredients' => [
        ['ingredient' => 'Arroz', 'quantity' => '300', 'unit' => 'g'],
        ['ingredient' => 'Huevos', 'quantity' => '1,5', 'unit' => 'piece'],
    ]];
    planInSlot(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'], $dish); // past: not counted
    planInSlot(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'], $dish);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('list.to_buy', 1)
        ->has('list.items', 1)
        ->where('list.items.0.name', 'Arroz')
        ->where('list.items.0.quantity', '300 g'));
});
