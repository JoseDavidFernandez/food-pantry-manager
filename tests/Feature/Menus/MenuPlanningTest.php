<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Menu;
use App\Models\PlannedDish;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Quantity;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday morning of the planned week: its dishes are still pending (RN-44).
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $this->recipe = Recipe::factory()->for($this->household)
        ->withIngredients([[$this->arroz, Quantity::of('200', Unit::Gram)]])
        ->create(['name' => 'Pollo con arroz', 'base_servings' => 4]);
});

function slot(string $week = '2026-10-19', int $day = 1, string $meal = 'lunch'): array
{
    return ['week' => $week, 'day' => $day, 'meal' => $meal];
}

test('CA-17: on Monday 00:30 in Madrid the current week starts that Monday, although UTC is still Sunday', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 23:30:00', 'UTC')); // Mon 26 Oct 00:30 Europe/Madrid

    expect(Week::current()->key())->toBe('2026-10-26');

    $this->get(route('menu.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('menu/Week')
            ->where('week.key', '2026-10-26')
            ->where('week.is_current', true)
            ->has('days', 7)
            ->where('days.0.label', 'Lunes 26')
            ->has('meals', 2));

    CarbonImmutable::setTestNow();
});

test('any date of the week opens the week of its Monday; invalid dates are 404', function () {
    $this->get(route('menu.show', '2026-10-22'))->assertInertia(fn (Assert $page) => $page->where('week.key', '2026-10-19'));
    $this->get('/menu/2026-02-31')->assertNotFound();
    $this->put('/menu/2026-10-19/8/lunch', ['type' => 'adhoc', 'name' => 'X', 'servings' => 1])->assertNotFound();
    $this->put('/menu/2026-10-19/1/breakfast', ['type' => 'adhoc', 'name' => 'X', 'servings' => 1])->assertNotFound();
});

test('a saved recipe is assigned to Monday lunch and the menu is persisted', function () {
    planInSlot(slot(), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 6])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $menu = $this->household->menus()->sole();
    expect($menu->week_start->format('Y-m-d'))->toBe('2026-10-19')
        ->and($menu->dishes()->sole()->only(['day', 'recipe_id', 'servings', 'name']))
        ->toBe(['day' => 1, 'recipe_id' => $this->recipe->id, 'servings' => 6, 'name' => null]);

    $this->get(route('menu.show', '2026-10-19'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('slots.1-lunch.0.name', 'Pollo con arroz')
            ->where('slots.1-lunch.0.servings', 6)
            ->where('slots.1-lunch.0.complete', true));
});

test('RN-16: changing a slot replaces its dish; removing it leaves the slot empty', function () {
    planInSlot(slot(), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4]);
    planInSlot(slot(), [
        'type' => 'adhoc', 'name' => 'Sobras de lentejas', 'servings' => 2,
        'ingredients' => [['ingredient' => 'Arroz', 'quantity' => '100', 'unit' => 'g']],
    ])->assertSessionHasNoErrors();

    $dish = PlannedDish::sole();
    expect($dish->name)->toBe('Sobras de lentejas')
        ->and($dish->recipe_id)->toBeNull()
        ->and($dish->ingredients()->count())->toBe(1);

    $this->delete(route('menu.dishes.destroy', dishIn(slot())))->assertRedirect();
    expect(PlannedDish::count())->toBe(0);
});

test('a simple dish only needs a name and servings; it is flagged as incomplete', function () {
    planInSlot(slot(day: 3, meal: 'dinner'), ['type' => 'adhoc', 'name' => 'Pizza casera', 'servings' => 2])
        ->assertSessionHasNoErrors();

    $this->get(route('menu.show', '2026-10-19'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('slots.3-dinner.0.type', 'adhoc')
            ->where('slots.3-dinner.0.complete', false)
            ->where('requirements.incomplete.0.dish', 'Pizza casera'));
});

test('validation: a recipe or a dish name is required; servings must be positive', function () {
    planInSlot(slot(), ['type' => 'recipe', 'servings' => 2])->assertSessionHasErrors('recipe_id');
    planInSlot(slot(), ['type' => 'adhoc', 'servings' => 2])->assertSessionHasErrors('name');
    planInSlot(slot(), ['type' => 'adhoc', 'name' => 'X', 'servings' => 0])->assertSessionHasErrors('servings');

    expect(PlannedDish::count())->toBe(0);
});

test('RN-14: archived recipes are not offered and cannot be assigned, but stay visible where already used', function () {
    planInSlot(slot(), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4]);
    $this->recipe->forceFill(['archived_at' => now()])->save();

    planInSlot(slot(day: 2), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4])
        ->assertSessionHasErrors('recipe_id');

    $this->get(route('menu.show', '2026-10-19'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recipes', 0)
            ->where('slots.1-lunch.0.recipe_archived', true));
});

test('CA-15: planning or removing dishes never changes the inventory', function () {
    InventoryItem::factory()->for($this->arroz)->of('1', Unit::Kilogram, StorageLocation::Pantry)->create();
    $before = InventoryItem::query()->get(['id', 'quantity', 'unit'])->toArray();

    planInSlot(slot(), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 8]);
    $this->get(route('menu.show', '2026-10-19'))->assertOk();
    $this->delete(route('menu.dishes.destroy', dishIn(slot())));

    expect(InventoryItem::query()->get(['id', 'quantity', 'unit'])->toArray())->toBe($before);
});

test('RN-36: menus are no longer closed; one closed before the change keeps its status but can be edited', function () {
    $this->travelTo('2026-10-19 08:00:00');
    $menu = Menu::create(['household_id' => $this->household->id, 'week_start' => '2026-10-19', 'status' => 'closed']);

    planInSlot(slot(), ['type' => 'adhoc', 'name' => 'Tortilla', 'servings' => 2])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect($menu->dishes()->count())->toBe(1)
        ->and($menu->fresh()->status->value)->toBe('closed');
    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page->missing('editable')->where('planned', 1));
});

test('CA-21: a dish moves to an empty slot and keeps its servings and ingredients', function () {
    planInSlot(slot(), [
        'type' => 'adhoc', 'name' => 'Sobras', 'servings' => 3,
        'ingredients' => [['ingredient' => 'Arroz', 'quantity' => '100', 'unit' => 'g']],
    ]);

    $this->patch(route('menu.dishes.move', dishIn(slot())), ['to_day' => 5, 'to_meal' => 'dinner'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    $dish = PlannedDish::sole();
    expect([$dish->day, $dish->meal->value, $dish->servings, $dish->ingredients()->count()])->toBe([5, 'dinner', 3, 1]);
});

test('CA-21: moving a dish onto a slot that has dishes adds it at the end; nothing is replaced', function () {
    planInSlot(slot(), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4]);
    planInSlot(slot(day: 2, meal: 'dinner'), ['type' => 'adhoc', 'name' => 'Tortilla', 'servings' => 2]);

    $this->patch(route('menu.dishes.move', dishIn(slot())), ['to_day' => 2, 'to_meal' => 'dinner'])->assertSessionHasNoErrors();

    $slots = PlannedDish::query()->orderBy('day')->orderBy('position')->get()->map(fn (PlannedDish $d) => "{$d->day}-{$d->meal->value}-{$d->position} {$d->displayName()}")->all();
    expect($slots)->toBe(['2-dinner-0 Tortilla', '2-dinner-1 Pollo con arroz']);
});

test('moving an empty slot or onto itself changes nothing; invalid targets are rejected', function () {
    planInSlot(slot(), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4]);

    $this->patch(route('menu.dishes.move', dishIn(slot())), ['to_day' => 1, 'to_meal' => 'lunch'])->assertSessionHasNoErrors();
    $this->patch(route('menu.dishes.move', dishIn(slot(day: 3))), ['to_day' => 1, 'to_meal' => 'dinner'])->assertSessionHasNoErrors();
    $this->patch(route('menu.dishes.move', dishIn(slot())), ['to_day' => 8, 'to_meal' => 'lunch'])->assertSessionHasErrors('to_day');
    $this->patch(route('menu.dishes.move', dishIn(slot())), ['to_day' => 2, 'to_meal' => 'breakfast'])->assertSessionHasErrors('to_meal');

    $dish = PlannedDish::sole();
    expect([$dish->day, $dish->meal->value])->toBe([1, 'lunch']);
});

test('CA-21 and CA-15: moving never touches the inventory, also in a menu closed before the change', function () {
    InventoryItem::factory()->for($this->arroz)->of('1', Unit::Kilogram, StorageLocation::Pantry)->create();
    $before = InventoryItem::query()->get(['id', 'quantity', 'unit'])->toArray();

    planInSlot(slot(), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4]);
    $this->patch(route('menu.dishes.move', dishIn(slot())), ['to_day' => 4, 'to_meal' => 'lunch']);
    expect(InventoryItem::query()->get(['id', 'quantity', 'unit'])->toArray())->toBe($before);

    $this->household->menus()->sole()->update(['status' => 'closed']);
    $this->patch(route('menu.dishes.move', dishIn(slot(day: 4))), ['to_day' => 6, 'to_meal' => 'dinner'])
        ->assertInertiaFlash('toast.type', 'success');

    expect(PlannedDish::sole()->day)->toBe(6)
        ->and(InventoryItem::query()->get(['id', 'quantity', 'unit'])->toArray())->toBe($before);
});

test('the recipe book offered next to the calendar lists active recipes with their servings and completeness', function () {
    Recipe::factory()->for($this->household)->create(['name' => 'Ensalada', 'base_servings' => 2]);

    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->has('recipes', 2)
        ->where('recipes.0.name', 'Ensalada')
        ->where('recipes.0.complete', false)
        ->where('recipes.1.name', 'Pollo con arroz')
        ->where('recipes.1.base_servings', 4)
        ->where('recipes.1.complete', true));
});

test('regression: moving a dish to another day with the same meal keeps that meal, and menu and home still load', function () {
    $this->travelTo('2026-10-21 10:00:00');
    planInSlot(slot(day: 2, meal: 'dinner'), ['type' => 'adhoc', 'name' => 'Sandwich Mixto', 'servings' => 2]);
    planInSlot(slot(day: 4, meal: 'dinner'), ['type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4]);

    $this->patch(route('menu.dishes.move', dishIn(slot(day: 2, meal: 'dinner'))), ['to_day' => 3, 'to_meal' => 'dinner'])->assertSessionHasNoErrors();
    $this->patch(route('menu.dishes.move', dishIn(slot(day: 3, meal: 'dinner'))), ['to_day' => 4, 'to_meal' => 'dinner'])->assertSessionHasNoErrors();

    $slots = PlannedDish::query()->orderBy('day')->orderBy('position')->get()->map(fn (PlannedDish $d) => "{$d->day}-{$d->meal->value} {$d->displayName()}")->all();
    expect($slots)->toBe(['4-dinner Pollo con arroz', '4-dinner Sandwich Mixto']);

    $this->get(route('menu.show', '2026-10-19'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('slots.4-dinner.1.name', 'Sandwich Mixto'));
    $this->get(route('dashboard'))->assertOk();
});
