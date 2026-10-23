<?php

use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Recipe;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * CA-02 / RN-02 for recipes and menus.
 */

beforeEach(function () {
    $this->owner = User::factory()->withHousehold()->create();
    $ownerHousehold = $this->owner->currentHousehold();
    Ingredient::factory()->for($ownerHousehold)->named('Arroz')->create();
    $this->recipe = Recipe::factory()->for($ownerHousehold)->create(['name' => 'Receta ajena']);
    $this->menu = Menu::create(['household_id' => $ownerHousehold->id, 'week_start' => '2026-10-19']);
    $this->menu->dishes()->create(['day' => 1, 'meal' => 'lunch', 'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 2]);

    $this->intruder = User::factory()->withHousehold()->create();
    $this->actingAs($this->intruder);
});

test('another household recipe cannot be seen or modified', function () {
    $this->get(route('recipes.index'))->assertInertia(fn (Assert $page) => $page->has('recipes', 0));
    $this->get(route('recipes.show', $this->recipe))->assertForbidden();
    $this->get(route('recipes.edit', $this->recipe))->assertForbidden();
    $this->put(route('recipes.update', $this->recipe), ['name' => 'X', 'base_servings' => 1])->assertForbidden();
    $this->patch(route('recipes.archive', $this->recipe))->assertForbidden();
    $this->delete(route('recipes.destroy', $this->recipe))->assertForbidden();

    expect($this->recipe->fresh()->name)->toBe('Receta ajena');
});

test('the menu of the same week is per household', function () {
    $this->get(route('menu.show', '2026-10-19'))
        ->assertInertia(fn (Assert $page) => $page->where('slots', [])->has('recipes', 0));

    $this->delete(route('menu.dishes.destroy', dishIn(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'])));
    $this->patch(route('menu.dishes.move', dishIn(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'])), ['to_day' => 2, 'to_meal' => 'dinner']);

    expect($this->menu->dishes()->count())->toBe(1)
        ->and($this->menu->dishes()->sole()->day)->toBe(1);
});

test('another household recipe or ingredient cannot be planned', function () {
    $slot = ['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'];

    planInSlot($slot, ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 2])
        ->assertSessionHasErrors('recipe_id');
    planInSlot($slot, ['type' => 'adhoc', 'name' => 'X', 'servings' => 2, 'ingredients' => [
        ['ingredient' => 'Arroz', 'quantity' => '100', 'unit' => 'g'],
    ]])->assertSessionHasErrors('ingredients.0.ingredient');
});
