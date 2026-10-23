<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Quantity;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $this->pollo = Ingredient::factory()->for($this->household)->named('Contramuslos de pollo')->create();
    $this->pollo->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Pollo', 'normalized_alias' => 'pollo']);
});

function recipePayload(array $overrides = []): array
{
    return [
        'name' => 'Pollo con arroz',
        'base_servings' => '4',
        'instructions' => 'Dorar el pollo y añadir el arroz.',
        'ingredients' => [
            ['ingredient' => 'arroz', 'quantity' => '320', 'unit' => 'g', 'note' => ''],
            ['ingredient' => 'Pollo', 'quantity' => '4', 'unit' => 'piece', 'note' => 'sin piel'],
            ['ingredient' => '', 'quantity' => '', 'unit' => 'g'],
        ],
        ...$overrides,
    ];
}

test('a recipe is created with catalog ingredients resolved by name or alias; empty rows are ignored', function () {
    $this->post(route('recipes.store'), recipePayload())->assertSessionHasNoErrors()->assertRedirect();

    $recipe = $this->household->recipes()->sole();
    $lines = $recipe->ingredients()->with('ingredient')->get();

    expect($recipe->name)->toBe('Pollo con arroz')
        ->and($recipe->base_servings)->toBe(4)
        ->and($lines->map(fn ($l) => "{$l->ingredient->name} {$l->quantity} {$l->unit->value}")->all())
        ->toBe(['Arroz 320.000 g', 'Contramuslos de pollo 4.000 piece'])
        ->and($lines[1]->note)->toBe('sin piel')
        ->and($this->household->ingredients()->count())->toBe(2);
});

test('RN-12: a recipe can be saved without ingredients', function () {
    $this->post(route('recipes.store'), ['name' => 'Ensalada libre', 'base_servings' => '2'])->assertSessionHasNoErrors();

    expect($this->household->recipes()->sole()->ingredients()->count())->toBe(0);
});

test('unknown ingredients are rejected with suggestions and are never created without asking', function () {
    $this->post(route('recipes.store'), recipePayload(['ingredients' => [
        ['ingredient' => 'Aroz', 'quantity' => '320', 'unit' => 'g'],
    ]]))->assertSessionHasErrors(['ingredients.0.ingredient' => '«Aroz» no está en tu catálogo. ¿Querías decir «Arroz»? Elige la sugerencia, corrige el nombre o créalo como ingrediente nuevo.']);

    expect(Recipe::count())->toBe(0)
        ->and($this->household->ingredients()->count())->toBe(2);
});

test('the same ingredient cannot appear twice with compatible units; incompatible units are allowed', function () {
    $this->post(route('recipes.store'), recipePayload(['ingredients' => [
        ['ingredient' => 'Arroz', 'quantity' => '300', 'unit' => 'g'],
        ['ingredient' => 'arroz', 'quantity' => '0,1', 'unit' => 'kg'],
    ]]))->assertSessionHasErrors('ingredients.1.ingredient');

    $this->post(route('recipes.store'), recipePayload(['ingredients' => [
        ['ingredient' => 'Arroz', 'quantity' => '300', 'unit' => 'g'],
        ['ingredient' => 'arroz', 'quantity' => '1', 'unit' => 'pack'],
    ]]))->assertSessionHasNoErrors();
});

test('quantities and servings are validated', function () {
    $this->post(route('recipes.store'), recipePayload(['base_servings' => '0', 'ingredients' => [
        ['ingredient' => 'Arroz', 'quantity' => '0', 'unit' => 'g'],
    ]]))->assertSessionHasErrors(['base_servings', 'ingredients.0.quantity']);
});

test('editing a recipe replaces its ingredient lines', function () {
    $this->post(route('recipes.store'), recipePayload());
    $recipe = $this->household->recipes()->sole();

    $this->put(route('recipes.update', $recipe), recipePayload(['name' => 'Arroz con pollo', 'ingredients' => [
        ['ingredient' => 'Arroz', 'quantity' => '0,4', 'unit' => 'kg'],
    ]]))->assertSessionHasNoErrors();

    expect($recipe->fresh()->name)->toBe('Arroz con pollo')
        ->and($recipe->ingredients()->get()->map(fn ($l) => "{$l->quantity} {$l->unit->value}")->all())->toBe(['0.400 kg']);
});

test('CA-05: the recipe page scales quantities for the chosen servings', function () {
    $recipe = Recipe::factory()->for($this->household)
        ->withIngredients([[$this->arroz, Quantity::of('200', Unit::Gram)]])
        ->create(['base_servings' => 4]);

    $this->get(route('recipes.show', [$recipe, 'servings' => 6]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('recipes/Show')
            ->where('servings', 6)
            ->where('ingredients.0.quantity', '300 g'));
});

test('CA-16: a recipe used in a menu cannot be deleted but can be archived and restored', function () {
    $recipe = Recipe::factory()->for($this->household)->create();
    $menu = Menu::create(['household_id' => $this->household->id, 'week_start' => '2026-10-19']);
    $menu->dishes()->create(['day' => 1, 'meal' => 'lunch', 'type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 2]);

    $this->delete(route('recipes.destroy', $recipe))->assertRedirect();
    expect(Recipe::whereKey($recipe->id)->exists())->toBeTrue();

    $this->patch(route('recipes.archive', $recipe))->assertRedirect();
    expect($recipe->fresh()->isArchived())->toBeTrue();

    $this->get(route('recipes.index'))->assertInertia(fn (Assert $page) => $page->has('recipes', 0)->where('archivedCount', 1));
    $this->get(route('recipes.index', ['archived' => 1]))->assertInertia(fn (Assert $page) => $page->where('recipes.0.archived', true));

    $this->patch(route('recipes.restore', $recipe))->assertRedirect();
    expect($recipe->fresh()->isArchived())->toBeFalse();
});

test('an unreferenced recipe can be deleted; its ingredients stay in the catalog', function () {
    $recipe = Recipe::factory()->for($this->household)
        ->withIngredients([[$this->arroz, Quantity::of('200', Unit::Gram)]])
        ->create();

    $this->delete(route('recipes.destroy', $recipe))->assertRedirect(route('recipes.index'));

    expect(Recipe::count())->toBe(0)
        ->and(Ingredient::whereKey($this->arroz->id)->exists())->toBeTrue();
});

test('an ingredient used in a recipe cannot be deleted', function () {
    Recipe::factory()->for($this->household)->withIngredients([[$this->arroz, Quantity::of('200', Unit::Gram)]])->create();

    $this->delete(route('ingredients.destroy', $this->arroz))->assertRedirect()->assertInertiaFlash('toast.type', 'error');

    expect(Ingredient::whereKey($this->arroz->id)->exists())->toBeTrue();
});

test('a food created from a recipe joins the shared catalog once and shows in Inventory without stock', function () {
    $this->post(route('recipes.store'), recipePayload(['name' => 'Ensalada', 'ingredients' => [
        ['ingredient' => 'Rúcula', 'quantity' => '1', 'unit' => 'pack', 'create' => '1'],
    ]]))->assertSessionHasNoErrors();

    // Another recipe that asks to create it again (or writes it differently) reuses the same food.
    $this->post(route('recipes.store'), recipePayload(['name' => 'Pizza', 'ingredients' => [
        ['ingredient' => 'rucula', 'quantity' => '50', 'unit' => 'g', 'create' => '1'],
    ]]))->assertSessionHasNoErrors();

    $rucula = Ingredient::where('name', 'Rúcula')->sole();
    expect($this->household->ingredients()->count())->toBe(3)
        ->and($rucula->recipeIngredients()->count())->toBe(2);

    $this->get(route('inventory.index', ['location' => 'none']))->assertInertia(fn (Assert $page) => $page
        ->where('counts.none', 3)
        ->where('foods.2.name', 'Rúcula')
        ->where('foods.2.recipe_count', 2)
        ->where('foods.2.stock_count', 0));
});
