<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const UNITS_WEEK = '2026-10-19';

beforeEach(function () {
    // Monday 19 Oct 2026 in Madrid: the planned Monday still counts (RN-36).
    $this->travelTo('2026-10-19 08:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->atun = Ingredient::factory()->for($this->household)->named('Atún en conserva')->create();
    $this->panMolde = Ingredient::factory()->for($this->household)->named('Pan de molde')->create();
    $this->jamon = Ingredient::factory()->for($this->household)->named('Jamón de York')->create();
});

test('cans, slices and deli slices work from recipe to list and partial receptions without conversions', function () {
    // Stock: 1 can and 150 g of tuna (two rows, never merged), 3 slices of bread.
    InventoryItem::factory()->for($this->atun)->of('1', Unit::Can, StorageLocation::Pantry)->create();
    InventoryItem::factory()->for($this->atun)->of('150', Unit::Gram, StorageLocation::Pantry)->create();
    $this->post(route('inventory.store'), ['ingredient' => 'Pan de molde', 'location' => 'pantry', 'quantity' => '3', 'unit' => 'slice'])
        ->assertSessionHasNoErrors();

    // Recipe for 2: 2 cans of tuna, 4 slices of bread, 3 deli slices of ham; planned for 3 servings.
    $recipe = Recipe::factory()->for($this->household)->withIngredients([
        [$this->atun, Quantity::of('2', Unit::Can)],
        [$this->panMolde, Quantity::of('4', Unit::Slice)],
        [$this->jamon, Quantity::of('3', Unit::DeliSlice)],
    ])->create(['name' => 'Bocadillos', 'base_servings' => 2]);

    planInSlot(['week' => UNITS_WEEK, 'day' => 1, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 3,
    ])->assertSessionHasNoErrors();

    // Needs: 3 cans − 1 can = 2; 6 slices − 3 = 3; 4.5 deli slices with no stock. Grams are never subtracted.
    $this->get(route('menu.show', UNITS_WEEK))->assertInertia(fn (Assert $page) => $page
        ->where('requirements.lines', fn ($lines) => collect($lines)->pluck('missing', 'ingredient')->all() === [
            'Atún en conserva' => '2 latas',
            'Jamón de York' => '4,5 lonchas',
            'Pan de molde' => '3 rebanadas',
        ]));

    // The list of the week (RN-36): opening it generates the lines.
    $this->get(route('shopping-list.period', ['start' => UNITS_WEEK, 'end' => '2026-10-25']))->assertOk();
    $listId = ShoppingList::sole()->id;

    $lines = ShoppingListItem::query()->get()->mapWithKeys(fn ($i) => [$i->name => $i->toQuantity()->format()])->all();
    // RN-23: 4.5 deli slices are rounded up to 5.
    expect($lines)->toEqual(['Atún en conserva' => '2 latas', 'Pan de molde' => '3 rebanadas', 'Jamón de York' => '5 lonchas']);

    $item = fn (string $name) => ShoppingListItem::where('name', $name)->sole()->id;

    // First reception: one can, all bread, 2 deli slices.
    $this->post(route('shopping-lists.receptions.store', $listId), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $item('Atún en conserva'), 'outcome' => 'received', 'quantity' => '1', 'unit' => 'can', 'location' => 'pantry'],
        ['item_id' => $item('Pan de molde'), 'outcome' => 'received', 'quantity' => '3', 'unit' => 'slice', 'location' => 'pantry'],
        ['item_id' => $item('Jamón de York'), 'outcome' => 'received', 'quantity' => '2', 'unit' => 'deli_slice', 'location' => 'fridge'],
    ]])->assertSessionHasNoErrors();

    $this->get(route('shopping-lists.receptions.create', $listId))->assertInertia(fn (Assert $page) => $page
        ->has('lines', 2)
        ->where('lines.0.name', 'Atún en conserva')
        ->where('lines.0.display', '1 lata')
        ->where('lines.1.display', '3 lonchas'));

    // Second reception: tuna arrives in grams — a separate row, and still 1 can pending.
    $this->post(route('shopping-lists.receptions.store', $listId), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => $item('Atún en conserva'), 'outcome' => 'received', 'quantity' => '80', 'unit' => 'g', 'location' => 'pantry'],
        ['item_id' => $item('Jamón de York'), 'outcome' => 'received', 'quantity' => '3', 'unit' => 'deli_slice', 'location' => 'fridge'],
    ]])->assertSessionHasNoErrors();

    $stock = InventoryItem::query()->with('ingredient')->orderBy('id')->get()
        ->map(fn (InventoryItem $row) => $row->ingredient->name.': '.$row->toQuantity()->format())->all();

    expect($stock)->toBe([
        'Atún en conserva: 2 latas',
        'Atún en conserva: 230 g',
        'Pan de molde: 6 rebanadas',
        'Jamón de York: 5 lonchas',
    ]);

    $this->get(route('shopping-lists.receptions.create', $listId))->assertInertia(fn (Assert $page) => $page
        ->has('lines', 1)
        // The grams are never subtracted from the cans still needed (RN-22).
        ->where('lines.0.display', '1 lata'));
});

test('the unit selectors offer the new units with their Spanish names', function () {
    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('units', fn ($units) => collect($units)->pluck('label', 'value')->only(['piece', 'can', 'jar', 'pack', 'slice', 'deli_slice'])->all() === [
            'piece' => 'unidades', 'can' => 'latas', 'jar' => 'botes', 'pack' => 'paquetes', 'slice' => 'rebanadas', 'deli_slice' => 'lonchas',
        ]));
});

test('unknown units are still rejected', function () {
    $this->post(route('inventory.store'), ['ingredient' => 'Atún en conserva', 'location' => 'pantry', 'quantity' => '1', 'unit' => 'lata'])
        ->assertSessionHasErrors('unit');
});
