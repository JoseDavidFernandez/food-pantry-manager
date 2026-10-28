<?php

use App\Actions\Ingredients\CategoryGuesser;
use App\Enums\HouseholdRole;
use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RN-49 / CA-43: usual foods offered in «Sin existencias», taken from the category dictionary,
 * created through the usual food creation and never added to the stock.
 */
beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
});

function basicNames(): array
{
    $names = [];

    test()->get(route('inventory.index', ['location' => 'none']))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$names) {
            $names = array_column($page->toArray()['props']['basics'], 'name');
        });

    return $names;
}

test('CA-43: the usual foods come from the dictionary, spelled properly and in the order of the aisles', function () {
    $this->get(route('inventory.index', ['location' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('basics.0', ['name' => 'Aguacate', 'category' => 'fruit', 'category_label' => 'Frutas'])
            ->where('basics', fn ($basics) => collect($basics)->pluck('category')->unique()->values()->all()
                === array_values(array_filter(
                    array_map(fn (IngredientCategory $category) => $category->value, IngredientCategory::cases()),
                    fn (string $category) => config("ingredient_categories.{$category}") !== null,
                )))
            ->where('basics', fn ($basics) => count($basics) === collect(config('ingredient_categories'))->flatten()->count()));

    expect(basicNames())->toContain('Plátano', 'Jamón de york', 'Champiñón', 'Crema de champiñones', 'Té');
});

test('only the «Sin existencias» tab carries them', function () {
    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page->where('basics', []));
    $this->get(route('inventory.index', ['location' => 'pantry']))->assertInertia(fn (Assert $page) => $page->where('basics', []));
});

test('CA-43: a food of the household hides its suggestion by name, alias or plural', function () {
    Ingredient::factory()->for($this->household)->named('PLÁTANO')->create();
    Ingredient::factory()->for($this->household)->named('Tomates')->create();
    $leche = Ingredient::factory()->for($this->household)->named('Leche entera')->create();
    $leche->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Leche', 'normalized_alias' => 'leche']);
    $ovo = Ingredient::factory()->for($this->household)->named('Ovo')->create();
    $ovo->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Huevos', 'normalized_alias' => 'huevos']);
    // The dictionary has «cereales»: a food named in singular hides it too.
    Ingredient::factory()->for($this->household)->named('Cereal')->create();

    expect(basicNames())->not->toContain('Plátano', 'Tomate', 'Leche', 'Huevo', 'Cereales')
        // Only exact names count: a similar name does not hide anything.
        ->toContain('Pimiento', 'Tomate frito', 'Pan rallado');
});

test('CA-43: the foods of another household do not hide anything', function () {
    $other = User::factory()->withHousehold()->create()->currentHousehold();
    Ingredient::factory()->for($other)->named('Plátano')->create();

    expect(basicNames())->toContain('Plátano');
});

test('CA-43: choosing a usual food creates it in the catalog with its category and no stock', function () {
    $this->post(route('ingredients.store'), ['name' => 'Caldo de pollo'])->assertSessionHasNoErrors();

    $caldo = Ingredient::where('name', 'Caldo de pollo')->sole();

    expect($caldo->household_id)->toBe($this->household->id)
        ->and($caldo->category)->toBe(IngredientCategory::SoupsBroths)
        ->and($caldo->default_unit)->toBeNull()
        ->and(InventoryItem::count())->toBe(0)
        ->and(basicNames())->not->toContain('Caldo de pollo');

    // It now appears among the foods without stock.
    $this->get(route('inventory.index', ['location' => 'none']))
        ->assertInertia(fn (Assert $page) => $page->where('counts.none', 1)->where('foods.0.name', 'Caldo de pollo'));
});

test('CA-43: an existing name or alias is refused and nothing is duplicated', function () {
    $tomate = Ingredient::factory()->for($this->household)->named('Tomate')->create();
    $tomate->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Tomate pera', 'normalized_alias' => 'tomate pera']);

    $this->post(route('ingredients.store'), ['name' => 'tomate'])->assertSessionHasErrors('name');
    $this->post(route('ingredients.store'), ['name' => 'Tomate pera', 'confirm_new' => '1'])->assertSessionHasErrors('name');

    expect(Ingredient::count())->toBe(1);
});

test('CA-43: a name that looks like an existing food asks for the usual confirmation', function () {
    Ingredient::factory()->for($this->household)->named('Pan')->create();

    $this->post(route('ingredients.store'), ['name' => 'Pan rallado'])
        ->assertSessionHasErrors(['name' => 'Se parece a «Pan». Si es un plural o un sinónimo, añádelo como alias; si es otro ingrediente, confirma que quieres crearlo.']);
    expect(Ingredient::where('normalized_name', 'pan rallado')->exists())->toBeFalse();

    $this->post(route('ingredients.store'), ['name' => 'Pan rallado', 'confirm_new' => '1'])->assertSessionHasNoErrors();
    expect(Ingredient::where('normalized_name', 'pan rallado')->sole()->category)->toBe(IngredientCategory::BakeryCereals)
        ->and(InventoryItem::count())->toBe(0);
});

test('RN-38: a viewer gets no suggestions and cannot create a food', function () {
    $viewer = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create();
    $this->actingAs($viewer);

    $this->get(route('inventory.index', ['location' => 'none']))->assertInertia(fn (Assert $page) => $page->where('basics', []));
    $this->post(route('ingredients.store'), ['name' => 'Plátano'])->assertForbidden();

    expect(Ingredient::count())->toBe(0);
});

test('the suggestions keep the automatic classification of the dictionary for every entry', function () {
    $this->get(route('inventory.index', ['location' => 'none']))
        ->assertInertia(function (Assert $page) {
            foreach ($page->toArray()['props']['basics'] as $basic) {
                expect(app(CategoryGuesser::class)->guess($basic['name'])?->value)->toBe($basic['category'], $basic['name']);
            }
        });
});
