<?php

use App\Enums\HouseholdRole;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\PlannedDish;
use App\Models\PlannedDishChoice;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\ShoppingListItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CA-30, CA-31 and CA-33. Point 5 (RN-42): alternative ingredients, one option per group, proposed from the stock and
 * confirmed or changed per dish. Point 9 (RN-43): preparations included in other recipes.
 */
beforeEach(function () {
    // Monday 19 Oct 2026 in Madrid: the whole week is ahead.
    $this->travelTo('2026-10-19 08:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    foreach (['Nata', 'Leche evaporada', 'Macarrones', 'Espaguetis', 'Carne picada', 'Tomate triturado', 'Cebolla'] as $name) {
        Ingredient::factory()->for($this->household)->named($name)->create();
    }
});

/**
 * @param  list<array<string, mixed>>  $ingredients
 * @param  list<array<string, mixed>>  $components
 */
function saveRecipe(string $name, int $servings, array $ingredients, array $components = [], ?Recipe $recipe = null): mixed
{
    $payload = ['name' => $name, 'base_servings' => $servings, 'ingredients' => $ingredients, 'components' => $components];

    return $recipe === null ? test()->post(route('recipes.store'), $payload) : test()->put(route('recipes.update', $recipe), $payload);
}

function recipeNamed(string $name): Recipe
{
    return Recipe::query()->where('name', $name)->sole();
}

function planRecipe(Recipe $recipe, int $servings, int $day = 2, string $week = '2026-10-19'): int
{
    test()->post(route('menu.dishes.store', ['week' => $week, 'day' => $day, 'meal' => 'lunch']), [
        'type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => $servings,
    ])->assertSessionHasNoErrors();

    return PlannedDish::query()->latest('id')->value('id');
}

/**
 * @return array<string, string> what to buy for the next days, by name
 */
function toBuy(int $days = 7): array
{
    return collect(test()->get(route('shopping-list.period', ['days' => $days]))->viewData('page')['props']['items'])
        ->pluck('display', 'name')->all();
}

function salsaConNata(): Recipe
{
    saveRecipe('Salsa', 2, [
        ['ingredient' => 'Cebolla', 'quantity' => '1', 'unit' => 'piece'],
        ['ingredient' => 'Nata', 'quantity' => '200', 'unit' => 'ml'],
        ['ingredient' => 'Leche evaporada', 'quantity' => '150', 'unit' => 'ml', 'alternative' => true],
    ])->assertSessionHasNoErrors();

    return recipeNamed('Salsa');
}

test('RN-42: a group counts once, with the option the stock covers proposed and shown as a proposal', function () {
    $salsa = salsaConNata();
    expect($salsa->ingredients()->whereNotNull('alternative_group')->pluck('alternative_group')->unique()->count())->toBe(1);

    // Nothing at home: the first option is proposed; only one of them is bought.
    planRecipe($salsa, 2);
    expect(toBuy())->toBe(['Cebolla' => '1 unidad', 'Nata' => '200 ml']);

    // Evaporated milk at home: it is proposed instead, and nothing is bought for the group.
    InventoryItem::factory()->for(Ingredient::where('name', 'Leche evaporada')->sole())->of('170', Unit::Millilitre, StorageLocation::Pantry)->create();
    expect(toBuy())->toBe(['Cebolla' => '1 unidad']);

    // The list says that a proposal is in use (never silently).
    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page->where('coverage.proposals', 1));

    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->where('slots.2-lunch.0.alternatives.0.confirmed', false)
        ->where('slots.2-lunch.0.alternatives.0.options.1.name', 'Leche evaporada')
        ->where('slots.2-lunch.0.alternatives.0.options.1.in_stock', true)
        ->where('slots.2-lunch.0.alternatives.0.chosen', Ingredient::where('name', 'Leche evaporada')->value('id')));
});

test('RN-42: the user confirms or changes the option, and it is kept across periods without duplicates', function () {
    $salsa = salsaConNata();
    InventoryItem::factory()->for(Ingredient::where('name', 'Leche evaporada')->sole())->of('170', Unit::Millilitre, StorageLocation::Pantry)->create();
    $dish = planRecipe($salsa, 2);
    $nata = Ingredient::where('name', 'Nata')->sole();

    // The user wants cream even if there is evaporated milk.
    $this->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => $nata->id])
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');

    expect(toBuy())->toBe(['Cebolla' => '1 unidad', 'Nata' => '200 ml'])
        ->and(toBuy(14))->toBe(['Cebolla' => '1 unidad', 'Nata' => '200 ml'])
        ->and(PlannedDishChoice::count())->toBe(1);
    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page->where('coverage.proposals', 0));

    // Changing it again replaces the choice; the list follows without duplicating the line.
    $leche = Ingredient::where('name', 'Leche evaporada')->sole();
    $this->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => $leche->id]);
    expect(toBuy())->toBe(['Cebolla' => '1 unidad'])
        ->and(toBuy(14))->toBe(['Cebolla' => '1 unidad'])
        ->and(PlannedDishChoice::sole()->ingredient_id)->toBe($leche->id)
        ->and(ShoppingListItem::where('name', 'Nata')->count())->toBe(0);

    // Editing the recipe keeps the choice while the option is still in the group.
    saveRecipe('Salsa', 2, [
        ['ingredient' => 'Nata', 'quantity' => '250', 'unit' => 'ml'],
        ['ingredient' => 'Leche evaporada', 'quantity' => '150', 'unit' => 'ml', 'alternative' => true],
    ], recipe: $salsa)->assertSessionHasNoErrors();
    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->where('slots.2-lunch.0.alternatives.0.confirmed', true)
        ->where('slots.2-lunch.0.alternatives.0.chosen', $leche->id));
});

test('RN-42: only options of the dish recipe can be chosen, by members who can write', function () {
    $salsa = salsaConNata();
    $dish = planRecipe($salsa, 2);
    $macarrones = Ingredient::where('name', 'Macarrones')->sole();

    $this->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => $macarrones->id])
        ->assertSessionHasErrors('ingredient_id');
    $this->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 9, 'ingredient_id' => $macarrones->id])
        ->assertSessionHasErrors('ingredient_id');

    $viewer = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create();
    $this->actingAs($viewer)->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => Ingredient::where('name', 'Nata')->value('id')])
        ->assertForbidden();
    $this->actingAs(User::factory()->withHousehold()->create())->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => 1])
        ->assertNotFound();

    expect(PlannedDishChoice::count())->toBe(0);
});

function bolonesa(): Recipe
{
    saveRecipe('Salsa boloñesa', 4, [
        ['ingredient' => 'Carne picada', 'quantity' => '400', 'unit' => 'g'],
        ['ingredient' => 'Tomate triturado', 'quantity' => '800', 'unit' => 'g'],
        ['ingredient' => 'Cebolla', 'quantity' => '1', 'unit' => 'piece'],
    ])->assertSessionHasNoErrors();

    return recipeNamed('Salsa boloñesa');
}

test('RN-43: a preparation is reused by several recipes, scaled, and its ingredients are added without duplicates', function () {
    $salsa = bolonesa();
    // Macarrones for 2 use 2 servings of sauce (of 4); espaguetis for 4 use 4 servings.
    saveRecipe('Macarrones boloñesa', 2, [['ingredient' => 'Macarrones', 'quantity' => '200', 'unit' => 'g'], ['ingredient' => 'Cebolla', 'quantity' => '1', 'unit' => 'piece']],
        [['recipe_id' => $salsa->id, 'servings' => 2]])->assertSessionHasNoErrors();
    saveRecipe('Espaguetis boloñesa', 4, [['ingredient' => 'Espaguetis', 'quantity' => '400', 'unit' => 'g']],
        [['recipe_id' => $salsa->id, 'servings' => 4]])->assertSessionHasNoErrors();

    // Macarrones for 4 (double): 400 g pasta, 2 + 1 onions, 4 servings of sauce = 400 g meat, 800 g tomato.
    planRecipe(recipeNamed('Macarrones boloñesa'), 4);
    // Espaguetis for 2 (half): 200 g pasta, 2 servings of sauce = 200 g meat, 400 g tomato, 0,5 onion.
    planRecipe(recipeNamed('Espaguetis boloñesa'), 2, day: 3);

    expect(toBuy())->toBe([
        'Carne picada' => '600 g',
        'Cebolla' => '4 unidades', // 2 + 1 + 0,5 rounded up for purchase
        'Espaguetis' => '200 g',
        'Macarrones' => '400 g',
        'Tomate triturado' => '1,2 kg',
    ]);

    // Changing the preparation changes every recipe that uses it.
    saveRecipe('Salsa boloñesa', 4, [
        ['ingredient' => 'Carne picada', 'quantity' => '500', 'unit' => 'g'],
        ['ingredient' => 'Tomate triturado', 'quantity' => '800', 'unit' => 'g'],
    ], recipe: $salsa)->assertSessionHasNoErrors();
    expect(toBuy())->toMatchArray(['Carne picada' => '750 g', 'Cebolla' => '2 unidades']);

    // The recipe page shows the preparation scaled and the combined total.
    $this->get(route('recipes.show', [recipeNamed('Macarrones boloñesa'), 'servings' => 4]))->assertInertia(fn (Assert $page) => $page
        ->where('components.0.name', 'Salsa boloñesa')
        ->where('components.0.servings', '4')
        ->where('totals', [
            ['ingredient' => 'Carne picada', 'quantity' => '500 g'],
            ['ingredient' => 'Cebolla', 'quantity' => '2 unidades'],
            ['ingredient' => 'Macarrones', 'quantity' => '400 g'],
            ['ingredient' => 'Tomate triturado', 'quantity' => '800 g'],
        ]));
});

test('RN-43: cycles are refused, at any depth, and a preparation in use cannot be deleted', function () {
    $salsa = bolonesa();
    saveRecipe('Macarrones boloñesa', 2, [['ingredient' => 'Macarrones', 'quantity' => '200', 'unit' => 'g']], [['recipe_id' => $salsa->id, 'servings' => 2]])
        ->assertSessionHasNoErrors();
    $macarrones = recipeNamed('Macarrones boloñesa');
    saveRecipe('Gratinado', 2, [['ingredient' => 'Nata', 'quantity' => '100', 'unit' => 'ml']], [['recipe_id' => $macarrones->id, 'servings' => 2]])
        ->assertSessionHasNoErrors();
    $gratinado = recipeNamed('Gratinado');

    // Itself, directly and through another recipe.
    saveRecipe('Salsa boloñesa', 4, [['ingredient' => 'Carne picada', 'quantity' => '400', 'unit' => 'g']], [['recipe_id' => $salsa->id, 'servings' => 1]], $salsa)
        ->assertSessionHasErrors('components.0.recipe_id');
    saveRecipe('Salsa boloñesa', 4, [['ingredient' => 'Carne picada', 'quantity' => '400', 'unit' => 'g']], [['recipe_id' => $macarrones->id, 'servings' => 1]], $salsa)
        ->assertSessionHasErrors('components.0.recipe_id');
    saveRecipe('Salsa boloñesa', 4, [['ingredient' => 'Carne picada', 'quantity' => '400', 'unit' => 'g']], [['recipe_id' => $gratinado->id, 'servings' => 1]], $salsa)
        ->assertSessionHasErrors('components.0.recipe_id');

    // The edit form does not even offer them.
    $this->get(route('recipes.edit', $salsa))->assertInertia(fn (Assert $page) => $page
        ->where('componentOptions', []));

    // Another household's recipe is not accepted; the same preparation twice neither.
    $foreign = Recipe::factory()->for(User::factory()->withHousehold()->create()->currentHousehold())->create();
    saveRecipe('Otra', 2, [], [['recipe_id' => $foreign->id, 'servings' => 1]])->assertSessionHasErrors('components.0.recipe_id');
    saveRecipe('Otra', 2, [], [['recipe_id' => $salsa->id, 'servings' => 1], ['recipe_id' => $salsa->id, 'servings' => 2]])->assertSessionHasErrors('components.1.recipe_id');

    // In use as a preparation: archived, never deleted, and the recipes using it keep working.
    $this->delete(route('recipes.destroy', $salsa))->assertInertiaFlash('toast.type', 'error');
    $this->patch(route('recipes.archive', $salsa));
    planRecipe($macarrones, 2);
    expect(Recipe::whereKey($salsa->id)->exists())->toBeTrue()
        ->and(RecipeComponent::count())->toBe(2)
        ->and(toBuy())->toHaveKey('Carne picada', '200 g');
});

test('RN-42 and RN-43 together: a group inside a preparation is chosen once per dish', function () {
    $salsa = salsaConNata();
    saveRecipe('Macarrones con salsa', 2, [['ingredient' => 'Macarrones', 'quantity' => '200', 'unit' => 'g']], [['recipe_id' => $salsa->id, 'servings' => 2]])
        ->assertSessionHasNoErrors();
    $dish = planRecipe(recipeNamed('Macarrones con salsa'), 4);

    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->where('slots.2-lunch.0.complete', true)
        ->where('slots.2-lunch.0.alternatives.0.recipe', 'Salsa')
        ->where('slots.2-lunch.0.alternatives.0.options.0.quantity', '400 ml'));

    $this->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => Ingredient::where('name', 'Leche evaporada')->value('id')])
        ->assertSessionHasNoErrors();

    expect(toBuy())->toBe(['Cebolla' => '2 unidades', 'Leche evaporada' => '300 ml', 'Macarrones' => '400 g']);
});

/**
 * The alternatives of the dishes on the menu page of the week, by slot and position.
 *
 * @return array<string, array<string, mixed>>
 */
function proposals(): array
{
    $slots = test()->get(route('menu.show', '2026-10-19'))->viewData('page')['props']['slots'];
    $result = [];

    foreach ((array) $slots as $slot => $dishes) {
        foreach ($dishes as $index => $dish) {
            foreach ($dish['alternatives'] as $alternative) {
                $chosen = collect($alternative['options'])->firstWhere('ingredient_id', $alternative['chosen']);
                $result["{$slot}.{$index}"] = [
                    'chosen' => $chosen['name'],
                    'confirmed' => $alternative['confirmed'],
                    'stale' => $alternative['stale'],
                    'in_stock' => $chosen['in_stock'],
                ];
            }
        }
    }

    ksort($result);

    return $result;
}

function leche(string $ml, StorageLocation $location = StorageLocation::Pantry): void
{
    InventoryItem::factory()->for(Ingredient::where('name', 'Leche evaporada')->sole())->of($ml, Unit::Millilitre, $location)->create();
}

test('point 1: two dishes compete for the same stock; the first one in the calendar takes it, the other gets another proposal', function () {
    $salsa = salsaConNata();
    leche('150');
    planRecipe($salsa, 2, day: 3); // Wednesday
    planRecipe($salsa, 2, day: 2); // Tuesday: eaten first

    expect(proposals())->toBe([
        '2-lunch.0' => ['chosen' => 'Leche evaporada', 'confirmed' => false, 'stale' => null, 'in_stock' => true],
        '3-lunch.0' => ['chosen' => 'Nata', 'confirmed' => false, 'stale' => null, 'in_stock' => false],
    ]);

    // The 150 ml are counted once: cream for Wednesday, nothing more of the milk.
    expect(toBuy())->toBe(['Cebolla' => '2 unidades', 'Nata' => '200 ml']);
    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page->where('coverage.proposals', 2));

    // More milk at home: both dishes are proposed with it, and nothing is bought for them.
    leche('200', StorageLocation::Fridge);
    expect(collect(proposals())->pluck('chosen')->all())->toBe(['Leche evaporada', 'Leche evaporada'])
        ->and(toBuy())->toBe(['Cebolla' => '2 unidades']);
});

test('point 1: fixed needs and confirmed choices take their stock before later proposals', function () {
    $salsa = salsaConNata();
    leche('150');

    // Monday: a one-off dish that needs the milk itself.
    test()->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 1, 'meal' => 'dinner']), [
        'type' => 'adhoc', 'name' => 'Natillas', 'servings' => 2,
        'ingredients' => [['ingredient' => 'Leche evaporada', 'quantity' => '150', 'unit' => 'ml']],
    ])->assertSessionHasNoErrors();
    $tuesday = planRecipe($salsa, 2, day: 2);

    expect(proposals()['2-lunch.0']['chosen'])->toBe('Nata');

    // The user wants the milk on Tuesday anyway: kept, and bought, never replaced.
    $this->patch(route('menu.dishes.choice', $tuesday), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => Ingredient::where('name', 'Leche evaporada')->value('id')]);
    $wednesday = planRecipe($salsa, 2, day: 3);

    expect(proposals())->toMatchArray([
        '2-lunch.0' => ['chosen' => 'Leche evaporada', 'confirmed' => true, 'stale' => null, 'in_stock' => false],
        '3-lunch.0' => ['chosen' => 'Nata', 'confirmed' => false, 'stale' => null, 'in_stock' => false],
    ])
        // 150 + 150 ml needed, 150 at home; one cream for Wednesday, no duplicated lines.
        ->and(toBuy())->toBe(['Cebolla' => '2 unidades', 'Leche evaporada' => '150 ml', 'Nata' => '200 ml']);

    // A confirmed choice on Wednesday too is kept when the stock changes.
    $this->patch(route('menu.dishes.choice', $wednesday), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => Ingredient::where('name', 'Nata')->value('id')]);
    leche('1000', StorageLocation::Fridge);
    expect(collect(proposals())->pluck('chosen', 'confirmed')->all())->toBe([1 => 'Nata'])
        ->and(proposals()['3-lunch.0']['chosen'])->toBe('Nata')
        ->and(toBuy())->toBe(['Cebolla' => '2 unidades', 'Nata' => '200 ml']);
});

test('point 1: different groups are assigned separately, and a period starting later leaves the stock to earlier dishes', function () {
    foreach (['Mantequilla', 'Aceite', 'Harina'] as $name) {
        Ingredient::factory()->for($this->household)->named($name)->create();
    }

    saveRecipe('Bechamel', 2, [
        ['ingredient' => 'Mantequilla', 'quantity' => '50', 'unit' => 'g'],
        ['ingredient' => 'Aceite', 'quantity' => '40', 'unit' => 'ml', 'alternative' => true],
        ['ingredient' => 'Harina', 'quantity' => '50', 'unit' => 'g'],
        ['ingredient' => 'Nata', 'quantity' => '200', 'unit' => 'ml'],
        ['ingredient' => 'Leche evaporada', 'quantity' => '150', 'unit' => 'ml', 'alternative' => true],
    ])->assertSessionHasNoErrors();
    $bechamel = recipeNamed('Bechamel');
    expect($bechamel->ingredients()->whereNotNull('alternative_group')->distinct()->count('alternative_group'))->toBe(2);

    InventoryItem::factory()->for(Ingredient::where('name', 'Aceite')->sole())->of('50', Unit::Millilitre, StorageLocation::Pantry)->create();
    leche('150');
    planRecipe($bechamel, 2, day: 2);
    planRecipe($bechamel, 2, day: 4);

    // Tuesday takes 40 of the 50 ml of oil and all the milk. Thursday: the oil left (10 ml) is
    // still used before buying another option; no milk is left, so cream is proposed.
    $slots = (array) test()->get(route('menu.show', '2026-10-19'))->viewData('page')['props']['slots'];
    $chosen = fn (string $slot) => collect($slots[$slot][0]['alternatives'])
        ->map(fn (array $a) => collect($a['options'])->firstWhere('ingredient_id', $a['chosen'])['name'])->all();
    expect($chosen('2-lunch'))->toBe(['Aceite', 'Leche evaporada'])
        ->and($chosen('4-lunch'))->toBe(['Aceite', 'Nata'])
        ->and(toBuy())->toBe(['Aceite' => '30 ml', 'Harina' => '100 g', 'Nata' => '200 ml']);

    // Custom dates from Wednesday: Tuesday's dish still takes its share before the period.
    $items = collect($this->get(route('shopping-list.period', ['start' => '2026-10-21', 'end' => '2026-10-25']))->viewData('page')['props']['items'])->pluck('display', 'name');
    expect($items->all())->toBe(['Aceite' => '30 ml', 'Harina' => '50 g', 'Nata' => '200 ml']);
});

test('point 1: a choice that the recipe no longer offers is reported and proposed again', function () {
    $salsa = salsaConNata();
    $dish = planRecipe($salsa, 2);
    $this->patch(route('menu.dishes.choice', $dish), ['recipe_id' => $salsa->id, 'group' => 1, 'ingredient_id' => Ingredient::where('name', 'Leche evaporada')->value('id')]);

    // The recipe now offers cream or onion-free cream cheese… no more evaporated milk.
    Ingredient::factory()->for($this->household)->named('Queso crema')->create();
    saveRecipe('Salsa', 2, [
        ['ingredient' => 'Nata', 'quantity' => '200', 'unit' => 'ml'],
        ['ingredient' => 'Queso crema', 'quantity' => '100', 'unit' => 'g', 'alternative' => true],
    ], recipe: $salsa)->assertSessionHasNoErrors();

    expect(proposals()['2-lunch.0'])->toBe(['chosen' => 'Nata', 'confirmed' => false, 'stale' => 'Leche evaporada', 'in_stock' => false])
        ->and(toBuy())->toBe(['Nata' => '200 ml']);
    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page->where('coverage.proposals', 1));
});
