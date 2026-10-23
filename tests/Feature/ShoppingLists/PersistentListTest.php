<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Menu;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RN-36: one persistent shopping list per household and period, reconciled with the current
 * needs every time it is opened; old weekly lists and finished periods are read-only history.
 */
beforeEach(function () {
    // Wednesday 21 Oct 2026, 12:00 in Madrid.
    $this->travelTo('2026-10-21 10:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $this->huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
    $this->recipe = Recipe::factory()->for($this->household)->withIngredients([
        [$this->arroz, Quantity::of('600', Unit::Gram)],
        [$this->huevos, Quantity::of('4', Unit::Piece)],
    ])->create(['name' => 'Arroz con huevo', 'base_servings' => 2]);

    // Thursday 22 lunch: inside the next 7, 14 and 30 days.
    planInSlot(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 2,
    ])->assertSessionHasNoErrors();
});

/**
 * @return array<string, array<string, mixed>>
 */
function openPeriod(array $query = []): array
{
    $items = [];

    test()->get(route('shopping-list.period', $query))->assertOk()
        ->assertInertia(function (Assert $page) use (&$items) {
            $page->component('shopping/List');
            $items = collect($page->toArray()['props']['items'])->keyBy('name')->all();
        });

    return $items;
}

function periodList(string $start, string $end): ShoppingList
{
    return ShoppingList::query()->whereDate('period_start', $start)->whereDate('period_end', $end)->sole();
}

/**
 * Every column of every line, to prove a list was not touched.
 *
 * @return list<array<string, mixed>>
 */
function linesOf(ShoppingList $list): array
{
    return $list->items()->orderBy('id')->get()->map->getAttributes()->all();
}

test('CA-10: the same period reuses its list: manual lines, edited quantities and purchases are recovered', function () {
    openPeriod();
    $list = periodList('2026-10-21', '2026-10-27');

    $this->post(route('shopping-lists.items.store', $list), ['item' => 'Papel de cocina'])->assertSessionHasNoErrors();
    $this->patch(route('shopping-lists.items.update', [$list, $list->items()->where('name', 'Arroz')->sole()]), ['quantity' => '1', 'unit' => 'kg']);
    $this->patch(route('shopping-lists.items.purchased', [$list, $list->items()->where('name', 'Huevos')->sole()]));

    // Another period in between, then back to the next 7 days.
    openPeriod(['days' => 30]);
    $items = openPeriod(['days' => 7]);

    expect(ShoppingList::where('household_id', $this->household->id)->whereDate('period_start', '2026-10-21')->count())->toBe(2)
        ->and($items['Papel de cocina']['is_generated'])->toBeFalse()
        ->and($items['Arroz'])->toMatchArray(['display' => '1 kg', 'is_user_modified' => true])
        ->and($items['Huevos'])->toMatchArray(['display' => '4 unidades', 'is_purchased' => true]);
});

test('a new period starts from the current overlapping list, which stays untouched as history', function () {
    openPeriod();
    $week = periodList('2026-10-21', '2026-10-27');
    $this->post(route('shopping-lists.items.store', $week), ['item' => 'Papel de cocina'])->assertSessionHasNoErrors();
    $this->patch(route('shopping-lists.items.purchased', [$week, $week->items()->where('name', 'Huevos')->sole()]));
    $before = linesOf($week);

    $items = openPeriod(['days' => 14]);

    expect($items)->toHaveKeys(['Arroz', 'Huevos', 'Papel de cocina'])
        ->and($items['Huevos']['is_purchased'])->toBeTrue()
        ->and(ShoppingListItem::where('name', 'Papel de cocina')->count())->toBe(2)
        ->and(linesOf($week->fresh()))->toBe($before);
});

test('the list follows recipes, «No comido» and stock without losing manual lines or valid purchases', function () {
    openPeriod();
    $list = periodList('2026-10-21', '2026-10-27');
    $this->post(route('shopping-lists.items.store', $list), ['item' => 'Papel de cocina']);
    $this->patch(route('shopping-lists.items.purchased', [$list, $list->items()->where('name', 'Huevos')->sole()]));

    // More servings: more eggs → the purchase no longer covers it and is unmarked (RN-28).
    planInSlot(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 3,
    ]);
    expect(openPeriod()['Huevos'])->toMatchArray(['display' => '6 unidades', 'is_purchased' => false, 'review_reason' => 'quantity_increased']);

    // «No comido»: nothing is needed any more; the manual line stays.
    $this->patch(route('menu.dishes.eaten', dishIn(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'])), ['eaten' => '0']);
    $items = openPeriod();
    expect($items)->toHaveKey('Papel de cocina')->not->toHaveKey('Arroz')->not->toHaveKey('Huevos');

    // Pending again and with stock at home: only what is missing.
    $this->patch(route('menu.dishes.eaten', dishIn(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'])), ['eaten' => '1']);
    InventoryItem::factory()->for($this->arroz)->of('500', Unit::Gram, StorageLocation::Pantry)->create();
    expect(openPeriod()['Arroz']['display'])->toBe('400 g')
        ->and(ShoppingListItem::where('shopping_list_id', $list->id)->where('name', 'Arroz')->count())->toBe(1);
});

test('a bought and received line leaves the list; bought but not received stays until it arrives', function () {
    openPeriod();
    $list = periodList('2026-10-21', '2026-10-27');
    foreach (['Arroz', 'Huevos'] as $name) {
        $this->patch(route('shopping-lists.items.purchased', [$list, $list->items()->where('name', $name)->sole()]));
    }

    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $list->items()->where('name', 'Arroz')->sole()->id, 'outcome' => 'received', 'quantity' => '600', 'unit' => 'g', 'location' => 'pantry'],
        ['item_id' => $list->items()->where('name', 'Huevos')->sole()->id, 'outcome' => 'not_purchased'],
    ]])->assertSessionHasNoErrors()->assertRedirect(route('shopping-list.period', ['days' => 7]));

    $items = openPeriod();
    expect($items)->not->toHaveKey('Arroz')
        ->and($items['Huevos'])->toMatchArray(['is_purchased' => true, 'needs_review' => false])
        ->and(InventoryItem::sole()->toQuantity()->format())->toBe('600 g');
});

test('a finished period is read-only history; a period that is already over creates no list', function () {
    openPeriod(['start' => '2026-10-21', 'end' => '2026-10-27']);
    $list = periodList('2026-10-21', '2026-10-27');
    $item = $list->items()->where('name', 'Arroz')->sole();
    $before = linesOf($list);

    $this->travelTo('2026-10-29 10:00:00');

    $this->get(route('shopping-lists.show', $list))->assertInertia(fn (Assert $page) => $page
        ->where('list.active', false)
        ->where('list.past', true)
        ->has('items', 2));
    $this->post(route('shopping-lists.items.store', $list), ['item' => 'Papel de cocina'])->assertInertiaFlash('toast.type', 'error');
    $this->patch(route('shopping-lists.items.purchased', [$list, $item]))->assertInertiaFlash('toast.type', 'error');
    $this->patch(route('shopping-lists.items.update', [$list, $item]), ['quantity' => '1', 'unit' => 'kg'])->assertInertiaFlash('toast.type', 'error');
    $this->delete(route('shopping-lists.items.destroy', [$list, $item]))->assertInertiaFlash('toast.type', 'error');
    $this->get(route('shopping-lists.receptions.create', $list))->assertRedirect(route('shopping-lists.show', $list));

    expect(linesOf($list->fresh()))->toBe($before);

    $count = ShoppingList::count();
    $this->get(route('shopping-list.period', ['start' => '2026-10-19', 'end' => '2026-10-25']))
        ->assertRedirect(route('shopping-list.period'));
    expect(ShoppingList::count())->toBe($count);
});

test('an old weekly list (closed menu) is kept intact as history and seeds the first period list', function () {
    // As left by closing a menu before 2026-10-21: a list bound to the menu, with a manual
    // line, an edited quantity and a purchase.
    $menu = Menu::where('household_id', $this->household->id)->sole();
    $menu->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $weekly = ShoppingList::create(['menu_id' => $menu->id, 'household_id' => $this->household->id, 'generated_at' => now()]);
    $manual = $weekly->items()->create(['origin' => ShoppingListItem::MANUAL, 'name' => 'Papel de cocina']);
    $rice = $weekly->items()->make(['origin' => ShoppingListItem::GENERATED, 'ingredient_id' => $this->arroz->id, 'name' => 'Arroz', 'match_key' => $this->arroz->id.':mass', 'is_user_modified' => true]);
    $rice->setQuantity(Quantity::of('1', Unit::Kilogram));
    $rice->setCalculated(Quantity::of('600', Unit::Gram));
    $rice->save();
    $eggs = $weekly->items()->make(['origin' => ShoppingListItem::GENERATED, 'ingredient_id' => $this->huevos->id, 'name' => 'Huevos', 'match_key' => $this->huevos->id.':count', 'is_purchased' => true]);
    $eggs->setQuantity(Quantity::of('4', Unit::Piece));
    $eggs->setCalculated(Quantity::of('4', Unit::Piece));
    $eggs->save();
    $before = linesOf($weekly);

    $items = openPeriod();

    expect($items['Papel de cocina']['is_generated'])->toBeFalse()
        ->and($items['Arroz'])->toMatchArray(['display' => '1 kg', 'is_user_modified' => true])
        ->and($items['Huevos']['is_purchased'])->toBeTrue()
        ->and(linesOf($weekly->fresh()))->toBe($before)
        ->and($weekly->fresh()->menu_id)->toBe($menu->id)
        ->and($manual->fresh())->not->toBeNull();

    // Old links open it as read-only history; it is listed with the others.
    $this->get(route('shopping-list.week', '2026-10-19'))->assertRedirect(route('shopping-lists.show', $weekly));
    $this->get(route('shopping-lists.show', $weekly))->assertInertia(fn (Assert $page) => $page
        ->where('list.active', false)
        ->where('list.weekly', true)
        ->has('items', 3));
    $this->get(route('shopping-lists.index'))->assertInertia(fn (Assert $page) => $page
        ->component('shopping/History')
        ->has('lists', 2)
        ->where('lists', fn ($lists) => collect($lists)->pluck('kind')->sort()->values()->all() === ['rolling', 'weekly']));
    $this->post(route('shopping-lists.items.store', $weekly), ['item' => 'Sal'])->assertInertiaFlash('toast.type', 'error');
});

test('another household cannot see, list or modify these lists', function () {
    openPeriod();
    $list = periodList('2026-10-21', '2026-10-27');
    $item = $list->items()->first();

    $this->actingAs(User::factory()->withHousehold()->create());

    $this->get(route('shopping-lists.show', $list))->assertNotFound();
    $this->post(route('shopping-lists.items.store', $list), ['item' => 'Sal'])->assertNotFound();
    $this->patch(route('shopping-lists.items.purchased', [$list, $item]))->assertNotFound();
    $this->patch(route('shopping-lists.items.update', [$list, $item]), ['quantity' => '1', 'unit' => 'kg'])->assertNotFound();
    $this->delete(route('shopping-lists.items.destroy', [$list, $item]))->assertNotFound();
    $this->get(route('shopping-lists.receptions.create', $list))->assertNotFound();
    $this->get(route('shopping-lists.index'))->assertInertia(fn (Assert $page) => $page->has('lists', 0));
    expect(openPeriod())->toBe([]);

    expect($item->fresh()->is_purchased)->toBeFalse()
        ->and($list->items()->count())->toBe(2);
});

test('«Próximos 7 días» keeps one list that moves with today, with its manual lines, purchases and receptions', function () {
    openPeriod();
    $list = periodList('2026-10-21', '2026-10-27');
    $this->post(route('shopping-lists.items.store', $list), ['item' => 'Papel de cocina']);
    $this->patch(route('shopping-lists.items.purchased', [$list, $list->items()->where('name', 'Huevos')->sole()]));
    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $list->items()->where('name', 'Arroz')->sole()->id, 'outcome' => 'received', 'quantity' => '250', 'unit' => 'g', 'location' => 'pantry'],
        ['item_id' => $list->items()->where('name', 'Huevos')->sole()->id, 'outcome' => 'not_purchased'],
        ['item_id' => $list->items()->where('name', 'Papel de cocina')->sole()->id, 'outcome' => 'not_purchased'],
    ]])->assertSessionHasNoErrors();

    // Two days later, and from Home too: the same list, its window starting today.
    $this->travelTo('2026-10-22 10:00:00');
    $this->get(route('dashboard'))->assertOk();
    $items = openPeriod(['days' => 7]);

    $list->refresh();
    expect(ShoppingList::where('household_id', $this->household->id)->count())->toBe(1)
        ->and([$list->period_start->format('Y-m-d'), $list->period_end->format('Y-m-d'), $list->rolling_days])->toBe(['2026-10-22', '2026-10-28', 7])
        ->and($list->receptions()->count())->toBe(1)
        ->and($items['Papel de cocina']['is_generated'])->toBeFalse()
        ->and($items['Huevos']['is_purchased'])->toBeTrue()
        ->and($items['Arroz'])->toMatchArray(['display' => '350 g', 'received' => '250 g', 'remaining' => '350 g']);

    // The quick list never goes to history; the 14 and 30 days lists are their own.
    openPeriod(['days' => 14]);
    expect(ShoppingList::whereNotNull('rolling_days')->pluck('rolling_days')->sort()->values()->all())->toBe([7, 14]);
});

test('custom dates keep their own fixed list, apart from the quick one, even with the same dates', function () {
    openPeriod(['start' => '2026-10-21', 'end' => '2026-10-27']);
    openPeriod(['days' => 7]);

    $lists = ShoppingList::orderBy('id')->get();
    expect($lists)->toHaveCount(1)
        // The custom list with today's dates is the one that became the quick list (same dates).
        ->and($lists->sole()->rolling_days)->toBe(7);

    // Custom dates on another day stay fixed and are not moved.
    openPeriod(['start' => '2026-10-23', 'end' => '2026-10-29']);
    $this->travelTo('2026-10-24 10:00:00');
    openPeriod(['days' => 7]);
    expect(periodList('2026-10-23', '2026-10-29')->rolling_days)->toBeNull()
        ->and(periodList('2026-10-24', '2026-10-30')->rolling_days)->toBe(7);
});

test('regression: an edited line fully received is complete, not «ya no se necesita»; a real change is still flagged', function () {
    openPeriod();
    $list = periodList('2026-10-21', '2026-10-27');
    $rice = $list->items()->where('name', 'Arroz')->sole();
    $this->patch(route('shopping-lists.items.update', [$list, $rice]), ['quantity' => '1', 'unit' => 'kg'])->assertSessionHasNoErrors();

    // Partial: 400 g of the 1 kg asked by the user.
    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $rice->id, 'outcome' => 'received', 'quantity' => '400', 'unit' => 'g', 'location' => 'pantry'],
        ['item_id' => $list->items()->where('name', 'Huevos')->sole()->id, 'outcome' => 'not_purchased'],
    ]]);
    expect(openPeriod()['Arroz'])->toMatchArray([
        'display' => '1 kg', 'received' => '400 g', 'remaining' => '600 g', 'is_received' => false, 'needs_review' => false,
    ]);

    // The rest arrives: complete, with what was received, and no warning.
    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $rice->id, 'outcome' => 'received', 'quantity' => '600', 'unit' => 'g', 'location' => 'pantry'],
        ['item_id' => $list->items()->where('name', 'Huevos')->sole()->id, 'outcome' => 'not_purchased'],
    ]]);
    expect(openPeriod()['Arroz'])->toMatchArray([
        'display' => '1 kg', 'received' => '1 kg', 'is_received' => true, 'needs_review' => false, 'review_reason' => null,
    ]);

    // A real change of the plan (more servings) is still flagged, once, with the new need.
    planInSlot(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4,
    ]);
    expect(openPeriod()['Arroz'])->toMatchArray([
        'display' => '1 kg', 'calculated' => '1,2 kg', 'needs_review' => true, 'review_reason' => 'calculation_changed', 'remaining' => null,
    ]);
    $this->patch(route('shopping-lists.items.reviewed', [$list, $rice]));
    expect(openPeriod()['Arroz']['needs_review'])->toBeFalse()
        ->and(InventoryItem::sole()->toQuantity()->format())->toBe('1000 g');
});

test('readable quantities: totals in kg and l, user quantities as written, partial and complete receptions', function () {
    // 2 servings → 600 g of rice; 4 servings → 1,2 kg. Stored in grams.
    planInSlot(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4,
    ]);
    $items = openPeriod();
    $list = periodList('2026-10-21', '2026-10-27');
    $rice = $list->items()->where('name', 'Arroz')->sole();

    expect($items['Arroz'])->toMatchArray(['display' => '1,2 kg', 'quantity' => '1200', 'unit' => 'g'])
        ->and($items['Huevos']['display'])->toBe('8 unidades')
        ->and($items['Arroz']['detail'])->toMatchArray(['need' => '1,2 kg', 'committed' => '1,2 kg', 'have' => null]);

    // Partial reception, written in grams: totals readable, nothing stored changes.
    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $rice->id, 'outcome' => 'received', 'quantity' => '450', 'unit' => 'g', 'location' => 'pantry'],
        ['item_id' => $list->items()->where('name', 'Huevos')->sole()->id, 'outcome' => 'not_purchased'],
    ]]);
    expect(openPeriod()['Arroz'])->toMatchArray(['display' => '750 g', 'received' => '450 g', 'remaining' => '750 g'])
        ->and($rice->fresh()->toQuantity()->format())->toBe('750 g');
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('list.items.0.quantity', '750 g'));

    // A quantity written by the user is shown as written.
    $this->patch(route('shopping-lists.items.update', [$list, $rice]), ['quantity' => '1000', 'unit' => 'g']);
    expect(openPeriod()['Arroz'])->toMatchArray(['display' => '1000 g', 'is_user_modified' => true]);

    // The user's 1000 g arrive in kilos: complete; the total received for the line reads in kg.
    $this->post(route('shopping-lists.receptions.store', $list), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $rice->id, 'outcome' => 'received', 'quantity' => '1', 'unit' => 'kg', 'location' => 'pantry'],
        ['item_id' => $list->items()->where('name', 'Huevos')->sole()->id, 'outcome' => 'not_purchased'],
    ]]);
    expect(openPeriod()['Arroz'])->toMatchArray(['received' => '1,45 kg', 'is_received' => true])
        ->and(InventoryItem::sole()->toQuantity()->format())->toBe('1,45 kg');

    // The reception history keeps each quantity as it was written.
    $this->get(route('shopping-list.period'))->assertInertia(fn (Assert $page) => $page
        ->where('receptions.0.items.0.display', '1 kg')
        ->where('receptions.1.items.0.display', '450 g'));
});
