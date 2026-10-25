<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\Quantity;

/**
 * Phase B, B1 (2026-10-22): the list shows what to buy first and folds the calculation. The
 * only new data is what earlier pending dishes take from the stock (`before`); the quantity
 * to buy does not change.
 */
test('the folded calculation explains what earlier dishes take, and what to buy stays the same', function () {
    atMadrid('2026-10-19 08:00');
    $user = User::factory()->withHousehold()->create();
    $this->actingAs($user);
    $huevos = Ingredient::factory()->for($user->currentHousehold())->named('Huevos')->create();
    $tortilla = Recipe::factory()->for($user->currentHousehold())->withIngredients([[$huevos, Quantity::of('4', Unit::Piece)]])
        ->create(['name' => 'Tortilla', 'base_servings' => 1]);
    InventoryItem::factory()->for($huevos)->of('6', Unit::Piece, StorageLocation::Fridge)->create();

    // Tuesday (before the period) and Thursday (in it).
    foreach ([2, 4] as $day) {
        $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => $day, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => $tortilla->id, 'servings' => 1]);
    }

    $line = collect($this->get(route('shopping-list.period', ['start' => '2026-10-22', 'end' => '2026-10-25']))->viewData('page')['props']['items'])->firstWhere('name', 'Huevos');

    // 4 for Thursday; 6 at home, 4 of them for Tuesday first: buy 2.
    expect($line['display'])->toBe('2 unidades')
        ->and($line['detail'])->toMatchArray(['need' => '4 unidades', 'before' => '4 unidades', 'have' => '6 unidades', 'registered' => null]);
});

test('R3: each line carries the category of its food, so the list groups like the aisles of a shop', function () {
    atMadrid('2026-10-19 08:00');
    $user = User::factory()->withHousehold()->create();
    $this->actingAs($user);
    $huevos = Ingredient::factory()->for($user->currentHousehold())->named('Huevos')->create(['category' => 'dairy_eggs']);
    $sal = Ingredient::factory()->for($user->currentHousehold())->named('Sal')->create(['category' => null]);
    $tortilla = Recipe::factory()->for($user->currentHousehold())->withIngredients([[$huevos, Quantity::of('4', Unit::Piece)], [$sal, Quantity::of('5', Unit::Gram)]])
        ->create(['name' => 'Tortilla', 'base_servings' => 1]);
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => $tortilla->id, 'servings' => 1]);

    $items = fn () => collect($this->get(route('shopping-list.period'))->viewData('page')['props']['items'])->keyBy('name');
    $items(); // The 7-day list is created when it is first opened.
    $list = ShoppingList::query()->where('rolling_days', 7)->sole();
    $this->post(route('shopping-lists.items.store', $list), ['item' => 'Papel de cocina', 'free_text' => '1'])->assertSessionHasNoErrors();

    expect($items()['Huevos'])->toMatchArray(['category' => 'dairy_eggs', 'category_label' => 'Lácteos y huevos'])
        ->and($items()['Sal'])->toMatchArray(['category' => null, 'category_label' => null])
        ->and($items()['Papel de cocina'])->toMatchArray(['category' => null, 'category_label' => null]);
});
