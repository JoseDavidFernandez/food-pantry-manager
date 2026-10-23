<?php

use App\Enums\HouseholdRole;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\PlannedDish;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Quantity;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CA-27. Point 1 (2026-10-22): several ordered dishes in the same lunch or dinner (RN-16), and point 2:
 * days already past are shown and explained, never silently ignored.
 */
beforeEach(function () {
    // Monday 19 Oct 2026 in Madrid: the whole week is ahead.
    $this->travelTo('2026-10-19 08:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
    $this->patatas = Ingredient::factory()->for($this->household)->named('Patatas')->create();
    $this->tortilla = Recipe::factory()->for($this->household)->withIngredients([
        [$this->huevos, Quantity::of('4', Unit::Piece)], [$this->patatas, Quantity::of('500', Unit::Gram)],
    ])->create(['name' => 'Tortilla de patatas', 'base_servings' => 2]);
    $this->francesa = Recipe::factory()->for($this->household)->withIngredients([[$this->huevos, Quantity::of('2', Unit::Piece)]])
        ->create(['name' => 'Tortilla francesa', 'base_servings' => 1]);
});

const TUESDAY_LUNCH = ['week' => '2026-10-19', 'day' => 2, 'meal' => 'lunch'];

function addDish(array $slot, array $payload): void
{
    test()->post(route('menu.dishes.store', $slot), $payload)->assertSessionHasNoErrors();
}

/**
 * @return list<string>
 */
function slotNames(int $day, string $meal): array
{
    return PlannedDish::query()->where('day', $day)->where('meal', $meal)->orderBy('position')->get()
        ->map(fn (PlannedDish $dish) => $dish->position.' '.$dish->displayName())->all();
}

test('a lunch holds several dishes in order, and the needs add all of them', function () {
    addDish(TUESDAY_LUNCH, ['type' => 'recipe', 'recipe_id' => $this->tortilla->id, 'servings' => 2]);
    addDish(TUESDAY_LUNCH, ['type' => 'adhoc', 'name' => 'Pisto', 'servings' => 2, 'ingredients' => [['ingredient' => 'Patatas', 'quantity' => '100', 'unit' => 'g']]]);
    addDish(TUESDAY_LUNCH, ['type' => 'adhoc', 'name' => 'Fruta', 'servings' => 2]);
    addDish(['week' => '2026-10-19', 'day' => 2, 'meal' => 'dinner'], ['type' => 'recipe', 'recipe_id' => $this->francesa->id, 'servings' => 2]);

    expect(slotNames(2, 'lunch'))->toBe(['0 Tortilla de patatas', '1 Pisto', '2 Fruta']);

    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->has('slots.2-lunch', 3)
        ->where('slots.2-lunch.1.name', 'Pisto')
        ->where('slots.2-dinner.0.name', 'Tortilla francesa')
        ->where('planned', 4)
        ->where('filledSlots', 2));

    // 4 + 4 eggs; 500 + 100 g of potatoes.
    $needs = collect($this->get(route('menu.show', '2026-10-19'))->viewData('page')['props']['requirements']['lines'])->pluck('need', 'ingredient');
    expect($needs->all())->toBe(['Huevos' => '8 unidades', 'Patatas' => '600 g']);
});

test('dishes are reordered, changed, moved and removed one by one', function () {
    addDish(TUESDAY_LUNCH, ['type' => 'adhoc', 'name' => 'A', 'servings' => 1]);
    addDish(TUESDAY_LUNCH, ['type' => 'adhoc', 'name' => 'B', 'servings' => 1]);
    addDish(TUESDAY_LUNCH, ['type' => 'adhoc', 'name' => 'C', 'servings' => 1]);

    $this->patch(route('menu.dishes.position', dishIn(TUESDAY_LUNCH, 2)), ['direction' => 'up']);
    expect(slotNames(2, 'lunch'))->toBe(['0 A', '1 C', '2 B']);
    $this->patch(route('menu.dishes.position', dishIn(TUESDAY_LUNCH, 0)), ['direction' => 'up']); // already first
    expect(slotNames(2, 'lunch'))->toBe(['0 A', '1 C', '2 B']);

    // Changing one dish keeps its place and the others.
    $this->put(route('menu.dishes.update', dishIn(TUESDAY_LUNCH, 1)), ['type' => 'recipe', 'recipe_id' => $this->francesa->id, 'servings' => 1])->assertSessionHasNoErrors();
    expect(slotNames(2, 'lunch'))->toBe(['0 A', '1 Tortilla francesa', '2 B']);

    // Moving appends to the target; the source is renumbered.
    $this->patch(route('menu.dishes.move', dishIn(TUESDAY_LUNCH, 0)), ['to_day' => 3, 'to_meal' => 'dinner']);
    expect(slotNames(2, 'lunch'))->toBe(['0 Tortilla francesa', '1 B'])
        ->and(slotNames(3, 'dinner'))->toBe(['0 A']);

    $this->delete(route('menu.dishes.destroy', dishIn(TUESDAY_LUNCH, 0)))->assertInertiaFlash('toast.type', 'success');
    expect(slotNames(2, 'lunch'))->toBe(['0 B']);
});

test('«No comido» releases only that dish, and planning never touches the stock', function () {
    InventoryItem::factory()->for($this->huevos)->of('3', Unit::Piece, StorageLocation::Fridge)->create();
    addDish(TUESDAY_LUNCH, ['type' => 'recipe', 'recipe_id' => $this->tortilla->id, 'servings' => 2]);
    addDish(TUESDAY_LUNCH, ['type' => 'recipe', 'recipe_id' => $this->francesa->id, 'servings' => 1]);

    $this->patch(route('menu.dishes.eaten', dishIn(TUESDAY_LUNCH, 0)), ['eaten' => '0']);

    $lines = collect($this->get(route('shopping-list.period', ['days' => 7]))->viewData('page')['props']['items'])->keyBy('name');
    expect($lines->keys()->all())->toBe([])   // 2 eggs needed, 3 at home; potatoes released
        ->and(PlannedDish::where('not_eaten_at', '!=', null)->count())->toBe(1)
        ->and(InventoryItem::sole()->quantity)->toBe('3.000');

    $this->patch(route('menu.dishes.eaten', dishIn(TUESDAY_LUNCH, 0)), ['eaten' => '1']);
    $lines = collect($this->get(route('shopping-list.period', ['days' => 7]))->viewData('page')['props']['items'])->pluck('display', 'name');
    expect($lines->all())->toBe(['Huevos' => '3 unidades', 'Patatas' => '500 g']);
});

test('dishes of another household cannot be changed, moved or removed; a viewer cannot plan', function () {
    addDish(TUESDAY_LUNCH, ['type' => 'adhoc', 'name' => 'Pisto', 'servings' => 2]);
    $id = dishIn(TUESDAY_LUNCH);

    $this->actingAs(User::factory()->withHousehold()->create());
    $this->put(route('menu.dishes.update', $id), ['type' => 'adhoc', 'name' => 'X', 'servings' => 1])->assertNotFound();
    $this->patch(route('menu.dishes.move', $id), ['to_day' => 5, 'to_meal' => 'lunch'])->assertNotFound();
    $this->patch(route('menu.dishes.position', $id), ['direction' => 'down'])->assertNotFound();
    $this->patch(route('menu.dishes.eaten', $id), ['eaten' => '0'])->assertNotFound();
    $this->delete(route('menu.dishes.destroy', $id))->assertNotFound();

    $viewer = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create();
    $this->actingAs($viewer)->post(route('menu.dishes.store', TUESDAY_LUNCH), ['type' => 'adhoc', 'name' => 'Y', 'servings' => 1])->assertForbidden();

    expect(slotNames(2, 'lunch'))->toBe(['0 Pisto']);
});

test('point 2: past days are flagged, planning on them is warned about, and the list explains it', function () {
    // Thursday 22 Oct 2026 in Madrid: Monday and Tuesday are past.
    $this->travelTo('2026-10-22 10:00:00');

    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => $this->tortilla->id, 'servings' => 2])
        ->assertInertiaFlash('toast.type', 'warning');
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 2, 'meal' => 'dinner']), ['type' => 'recipe', 'recipe_id' => $this->francesa->id, 'servings' => 1]);

    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->where('days.0.is_past', true)
        ->where('days.2.is_past', true)
        ->where('days.3.is_past', false));

    // Nothing to buy for the next days, and the page says why.
    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page
        ->where('items', [])
        ->where('coverage', ['dishes' => 0, 'past_this_week' => 2, 'proposals' => 0]));

    // A dish from today on generates its needs.
    $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 5, 'meal' => 'lunch']), ['type' => 'recipe', 'recipe_id' => $this->tortilla->id, 'servings' => 2])
        ->assertInertiaFlash('toast.type', 'success');
    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page
        ->has('items', 2)
        ->where('coverage.dishes', 1));
});
