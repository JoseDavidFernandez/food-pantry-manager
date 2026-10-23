<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Support\Quantity;

/**
 * CA-37, RN-45 (Phase A): editing a recipe never changes what past meals used. A revision with the
 * earlier composition is saved when dishes already took place; each dish uses the composition
 * in force at its reference time. Tortilla of 4 eggs for 2, lunch Monday to Friday (week of
 * 19 Oct 2026), 18 eggs on Monday at 08:00.
 */
beforeEach(function () {
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
    $this->huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
    $this->tortilla = Recipe::factory()->for($this->household)->withIngredients([[$this->huevos, Quantity::of('4', Unit::Piece)]])
        ->create(['name' => 'Tortilla', 'base_servings' => 2]);

    foreach ([1, 2, 3, 4, 5] as $day) {
        $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => $day, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => $this->tortilla->id, 'servings' => 2]);
    }

    $this->post(route('inventory.store'), ['ingredient' => 'Huevos', 'quantity' => '18', 'unit' => 'piece', 'location' => 'fridge']);
});

function tortillaWith(string $eggs): mixed
{
    return test()->put(route('recipes.update', test()->tortilla), [
        'name' => 'Tortilla', 'base_servings' => 2,
        'ingredients' => [['ingredient' => 'Huevos', 'quantity' => $eggs, 'unit' => 'piece']],
    ]);
}

test('no revision while no dish has taken place, nor for an edit that changes nothing', function () {
    tortillaWith('5')->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Receta actualizada.');
    expect(RecipeRevision::count())->toBe(0);

    atMadrid('2026-10-22 10:00');
    tortillaWith('5');
    expect(RecipeRevision::count())->toBe(0);
});

test('Thursday: past meals keep 4 eggs; the next ones use 5; undoing is another edit', function () {
    atMadrid('2026-10-22 10:00');
    tortillaWith('5')->assertInertiaFlash('toast.message', 'Receta actualizada. Los platos que ya han pasado conservan la receta anterior en el inventario previsto; los próximos usan la nueva.');

    expect(RecipeRevision::count())->toBe(1)
        ->and(estimateOf($this->huevos)['total'])->toBe('6');

    atMadrid('2026-10-22 11:00');
    tortillaWith('4');
    expect(RecipeRevision::count())->toBe(2)
        ->and(estimateOf($this->huevos)['total'])->toBe('6');
});

test('Wednesday before lunch: Wednesday already uses the new recipe', function () {
    atMadrid('2026-10-21 12:00');
    tortillaWith('5');
    atMadrid('2026-10-22 10:00');

    expect(estimateOf($this->huevos)['total'])->toBe('5');
});

test('a chain of edits: each meal uses the version in force at its reference time', function () {
    atMadrid('2026-10-19 16:00');
    tortillaWith('5');
    atMadrid('2026-10-20 16:00');
    tortillaWith('6');
    atMadrid('2026-10-22 10:00');

    // Monday 4, Tuesday 5, Wednesday 6.
    expect(estimateOf($this->huevos)['total'])->toBe('3')
        ->and(RecipeRevision::count())->toBe(2);
});

test('an edit exactly at the reference time leaves that meal with the earlier recipe', function () {
    atMadrid('2026-10-21 15:00');
    tortillaWith('6');
    atMadrid('2026-10-22 10:00');

    expect(estimateOf($this->huevos)['total'])->toBe('6');
});

test('a preparation edited later: past dishes that include it keep its earlier quantities', function () {
    $tomate = Ingredient::factory()->for($this->household)->named('Tomate triturado')->create();
    $salsa = Recipe::factory()->for($this->household)->withIngredients([[$tomate, Quantity::of('400', Unit::Gram)]])->create(['name' => 'Salsa', 'base_servings' => 2]);
    $this->post(route('recipes.store'), ['name' => 'Macarrones', 'base_servings' => 2, 'ingredients' => [], 'components' => [['recipe_id' => $salsa->id, 'servings' => 2]]])->assertSessionHasNoErrors();
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 1, 'meal' => 'dinner']), ['type' => 'recipe', 'recipe_id' => Recipe::where('name', 'Macarrones')->value('id'), 'servings' => 2]);
    $this->post(route('inventory.store'), ['ingredient' => 'Tomate triturado', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry']);

    atMadrid('2026-10-22 10:00');
    $this->put(route('recipes.update', $salsa), ['name' => 'Salsa', 'base_servings' => 2, 'ingredients' => [['ingredient' => 'Tomate triturado', 'quantity' => '800', 'unit' => 'g']]])->assertSessionHasNoErrors();

    expect(RecipeRevision::where('recipe_id', $salsa->id)->count())->toBe(1)
        ->and(estimateOf($tomate)['total'])->toBe('600');
});

test('groups of alternatives are taken from the version in force', function () {
    $nata = Ingredient::factory()->for($this->household)->named('Nata')->create();
    $leche = Ingredient::factory()->for($this->household)->named('Leche evaporada')->create();
    $this->post(route('recipes.store'), ['name' => 'Crema', 'base_servings' => 1, 'ingredients' => [
        ['ingredient' => 'Nata', 'quantity' => '200', 'unit' => 'ml'],
        ['ingredient' => 'Leche evaporada', 'quantity' => '150', 'unit' => 'ml', 'alternative' => true],
    ]]);
    $crema = Recipe::where('name', 'Crema')->sole();
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 1, 'meal' => 'dinner']), ['type' => 'recipe', 'recipe_id' => $crema->id, 'servings' => 1]);
    $this->post(route('inventory.store'), ['ingredient' => 'Nata', 'quantity' => '500', 'unit' => 'ml', 'location' => 'fridge']);

    // Later the recipe no longer offers cream: Monday's dinner still used it.
    atMadrid('2026-10-22 10:00');
    $this->put(route('recipes.update', $crema), ['name' => 'Crema', 'base_servings' => 1, 'ingredients' => [['ingredient' => 'Leche evaporada', 'quantity' => '150', 'unit' => 'ml']]]);

    expect(estimateOf($nata)['total'])->toBe('300');
});
