<?php

use App\Enums\HouseholdRole;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RN-02 and RN-38: the members of a household work on the same data; other households never
 * see nor change it, whatever ids they send.
 */
beforeEach(function () {
    $this->travelTo('2026-10-19 08:00:00');
    $this->ana = User::factory()->withHousehold()->create(['name' => 'Ana']);
    $this->household = $this->ana->currentHousehold();
    $this->luis = User::factory()->memberOf($this->household, HouseholdRole::Editor)->create(['name' => 'Luis']);
    $this->eva = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create(['name' => 'Eva']);
    $this->stranger = User::factory()->withHousehold()->create(['name' => 'Otro']);
});

/**
 * @return array<string, mixed>
 */
function pageProps(string $url): array
{
    $props = [];
    test()->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

test('CA-26: what one member creates, the others see: stock, recipes, menu, list and receptions', function () {
    // Luis (editor) adds stock and a recipe; Ana (admin) plans it.
    $this->actingAs($this->luis);
    $this->post(route('inventory.store'), ['ingredient' => 'Arroz', 'create_ingredient' => '1', 'location' => 'pantry', 'quantity' => '200', 'unit' => 'g'])->assertSessionHasNoErrors();
    $this->post(route('recipes.store'), ['name' => 'Paella', 'base_servings' => '2', 'ingredients' => [['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]])->assertSessionHasNoErrors();
    $recipe = Recipe::sole();

    $this->actingAs($this->ana);
    planInSlot(['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch'], ['type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 2])
        ->assertSessionHasNoErrors();

    // Eva (viewer) sees all of it, and the same shopping list (one list, shared).
    $this->actingAs($this->eva);
    expect(collect(pageProps(route('inventory.index'))['items'])->pluck('ingredient')->all())->toBe(['Arroz'])
        ->and(collect(pageProps(route('recipes.index'))['recipes'])->pluck('name')->all())->toBe(['Paella'])
        ->and(pageProps(route('menu.show', '2026-10-19'))['slots']['2-lunch'][0]['name'])->toBe('Paella');
    $items = pageProps(route('shopping-list.period'))['items'];
    expect(collect($items)->pluck('display', 'name')->all())->toBe(['Arroz' => '400 g']);

    // Luis receives the purchase; Ana and Eva see the new stock and the reception.
    $this->actingAs($this->luis);
    $list = ShoppingList::sole();
    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $list->items()->sole()->id, 'outcome' => 'received', 'quantity' => '400', 'unit' => 'g', 'location' => 'pantry'],
    ]])->assertSessionHasNoErrors();

    foreach ([$this->ana, $this->eva] as $member) {
        $this->actingAs($member);
        expect(pageProps(route('inventory.index'))['items'][0]['quantity'])->toBe('600')
            ->and(pageProps(route('shopping-list.period'))['receptions'])->toHaveCount(1);
    }

    expect(ShoppingList::count())->toBe(1)
        ->and(InventoryItem::count())->toBe(1);
});

test('CA-26: another household sees none of it and cannot change it with guessed ids', function () {
    $this->actingAs($this->luis);
    $this->post(route('inventory.store'), ['ingredient' => 'Arroz', 'create_ingredient' => '1', 'location' => 'pantry', 'quantity' => '200', 'unit' => 'g']);
    $this->post(route('recipes.store'), ['name' => 'Paella', 'base_servings' => '2', 'ingredients' => [['ingredient' => 'Arroz', 'quantity' => '600', 'unit' => 'g']]]);
    $recipe = Recipe::sole();
    planInSlot(['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch'], ['type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 2]);
    $this->get(route('shopping-list.period'));
    $list = ShoppingList::sole();
    $item = $list->items()->sole();
    $stock = InventoryItem::sole();
    $arroz = Ingredient::sole();

    $this->actingAs($this->stranger);
    expect(pageProps(route('inventory.index'))['items'])->toBe([])
        ->and(pageProps(route('recipes.index'))['recipes'])->toBe([])
        ->and(pageProps(route('menu.show', '2026-10-19'))['slots'])->toBe([])
        ->and(pageProps(route('shopping-lists.index'))['lists'])->toBe([])
        ->and(collect(pageProps(route('household.show'))['members'])->pluck('name')->all())->toBe(['Otro']);

    $this->get(route('recipes.show', $recipe))->assertForbidden();
    $this->put(route('inventory.update', $stock), ['quantity' => '1', 'unit' => 'g', 'location' => 'pantry'])->assertForbidden();
    $this->put(route('ingredients.update', $arroz), ['name' => 'Hackeado'])->assertForbidden();
    $this->get(route('shopping-lists.show', $list))->assertNotFound();
    $this->patch(route('shopping-lists.items.purchased', [$list, $item]))->assertNotFound();
    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => []])->assertNotFound();
    $this->delete(route('menu.dishes.destroy', dishIn(['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch'])));
    $this->patch(route('household.members.update', $this->luis), ['role' => 'viewer'])->assertNotFound();

    expect($stock->fresh()->quantity)->toBe('200.000')
        ->and($arroz->fresh()->name)->toBe('Arroz')
        ->and($item->fresh()->is_purchased)->toBeFalse()
        ->and($this->household->menus()->sole()->dishes()->count())->toBe(1)
        ->and($this->luis->householdRole())->toBe(HouseholdRole::Editor);
});
