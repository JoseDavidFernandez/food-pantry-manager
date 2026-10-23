<?php

use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);
});

test('CA-19: an ingredient can be created with a category or without one', function () {
    $this->post(route('ingredients.store'), ['intent' => 'create', 'name' => 'Pechuga de pollo', 'category' => 'meat'])
        ->assertSessionHasNoErrors();
    $this->post(route('ingredients.store'), ['intent' => 'create', 'name' => 'Kombu'])
        ->assertSessionHasNoErrors();

    expect(Ingredient::where('name', 'Pechuga de pollo')->sole()->category)->toBe(IngredientCategory::Meat)
        ->and(Ingredient::where('name', 'Kombu')->sole()->category)->toBeNull();
});

test('the category can be changed or removed when editing', function () {
    $ingredient = Ingredient::factory()->for($this->household)->named('Atún en conserva')->create();

    $this->put(route('ingredients.update', $ingredient), ['name' => 'Atún en conserva', 'category' => 'preserves'])
        ->assertSessionHasNoErrors();
    expect($ingredient->fresh()->category)->toBe(IngredientCategory::Preserves);

    $this->put(route('ingredients.update', $ingredient), ['name' => 'Atún en conserva', 'category' => ''])
        ->assertSessionHasNoErrors();
    expect($ingredient->fresh()->category)->toBeNull();
});

test('only known categories are accepted', function () {
    $this->post(route('ingredients.store'), ['intent' => 'create', 'name' => 'Pizza', 'category' => 'frozen'])
        ->assertSessionHasErrors('category');

    expect(Ingredient::count())->toBe(0);
});

test('CA-19: a new food gets its category from the local dictionary; unknown names stay unclassified', function () {
    $this->post(route('ingredients.store'), ['intent' => 'create', 'name' => '  POLLO '])->assertSessionHasNoErrors();
    // Accents, case and plural do not matter; it also applies when creating from the inventory.
    $this->post(route('inventory.store'), ['ingredient' => 'Merluzas', 'create_ingredient' => '1', 'location' => 'freezer', 'quantity' => '1', 'unit' => 'piece'])
        ->assertSessionHasNoErrors();
    $this->post(route('inventory.store'), ['ingredient' => 'Limónes', 'create_ingredient' => '1', 'location' => 'fridge', 'quantity' => '1', 'unit' => 'piece']);
    // Only exact names: a dubious partial match is not a reliable category.
    $this->post(route('ingredients.store'), ['intent' => 'create', 'name' => 'Pollo al curry precocinado', 'confirm_new' => '1'])->assertSessionHasNoErrors();

    expect(Ingredient::where('name', 'POLLO')->sole()->category)->toBe(IngredientCategory::Meat)
        ->and(Ingredient::where('name', 'Merluzas')->sole()->category)->toBe(IngredientCategory::Fish)
        ->and(Ingredient::where('name', 'Limónes')->sole()->category)->toBe(IngredientCategory::Fruit)
        ->and(Ingredient::where('name', 'Pollo al curry precocinado')->sole()->category)->toBeNull();
});

test('CA-19: the category chosen by the user wins, it can be corrected, and nothing is duplicated', function () {
    // Explicit choice: kept even if the dictionary says otherwise.
    $this->post(route('ingredients.store'), ['intent' => 'create', 'name' => 'Tomate', 'category' => 'preserves'])->assertSessionHasNoErrors();
    $tomate = Ingredient::where('name', 'Tomate')->sole();
    expect($tomate->category)->toBe(IngredientCategory::Preserves);

    // Corrected from Inventory, also to «Sin clasificar»: editing never guesses again.
    $this->put(route('ingredients.update', $tomate), ['name' => 'Tomate', 'category' => ''])->assertSessionHasNoErrors();
    expect($tomate->fresh()->category)->toBeNull();

    // An equivalent name reuses the food (no duplicate, category untouched).
    $this->post(route('inventory.store'), ['ingredient' => 'TOMATE', 'create_ingredient' => '1', 'location' => 'fridge', 'quantity' => '2', 'unit' => 'piece'])
        ->assertSessionHasNoErrors();
    expect(Ingredient::count())->toBe(1)
        ->and($tomate->fresh()->category)->toBeNull();
});

test('a new ingredient created while adding stock can get its category there', function () {
    $this->post(route('inventory.store'), [
        'ingredient' => 'Merluza', 'create_ingredient' => '1', 'category' => 'fish',
        'location' => 'freezer', 'quantity' => '2', 'unit' => 'piece',
    ])->assertSessionHasNoErrors();

    $merluza = Ingredient::where('name', 'Merluza')->sole();
    expect($merluza->category)->toBe(IngredientCategory::Fish)
        ->and(InventoryItem::sole()->location)->toBe(StorageLocation::Freezer);
});

test('the category of an existing ingredient is not changed by adding stock', function () {
    $leche = Ingredient::factory()->for($this->household)->named('Leche')->create(['category' => IngredientCategory::DairyEggs]);

    $this->post(route('inventory.store'), ['ingredient' => 'Leche', 'category' => 'drinks', 'location' => 'fridge', 'quantity' => '1', 'unit' => 'l'])
        ->assertSessionHasNoErrors();

    expect($leche->fresh()->category)->toBe(IngredientCategory::DairyEggs);
});

test('CA-19: stock inherits the category of its ingredient without storing it again', function () {
    $pollo = Ingredient::factory()->for($this->household)->named('Pollo')->create(['category' => IngredientCategory::Meat]);
    InventoryItem::factory()->for($pollo)->of('500', Unit::Gram, StorageLocation::Freezer)->create();

    expect(Schema::hasColumn('inventory_items', 'category'))->toBeFalse();

    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('items.0.category', 'meat')
        ->where('items.0.category_label', 'Carne y aves')
        ->where('items.0.location', 'freezer')
        ->where('items.0.location_label', 'Congelador')
        ->has('categories', count(IngredientCategory::cases()))
        ->where('counts.freezer', 1));

    // Changing the ingredient changes how all its stock is classified.
    $pollo->update(['category' => IngredientCategory::Other]);
    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page->where('items.0.category', 'other'));
});

test('ingredients without a category are shown as «Sin clasificar»', function () {
    Ingredient::factory()->for($this->household)->named('Sal')->create();

    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('foods.0.category', null)
        ->where('foods.0.category_label', 'Sin clasificar')
        ->where('foods.0.image_url', null));
});

test('existing ingredients keep all their data after the migration (no category, no image)', function () {
    foreach (['category', 'image_path', 'image_provider', 'image_source_url', 'image_author', 'image_license', 'image_license_url', 'image_updated_at'] as $column) {
        expect(Schema::hasColumn('ingredients', $column))->toBeTrue();
    }

    $arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create(['default_unit' => Unit::Gram]);

    expect($arroz->fresh())
        ->name->toBe('Arroz')
        ->default_unit->toBe(Unit::Gram)
        ->category->toBeNull()
        ->image_path->toBeNull();
});

test('the freezer is a location like the others: compatible rows merge per location', function () {
    $pollo = Ingredient::factory()->for($this->household)->named('Pollo')->create();
    InventoryItem::factory()->for($pollo)->of('500', Unit::Gram, StorageLocation::Freezer)->create();

    $this->post(route('inventory.store'), ['ingredient' => 'Pollo', 'location' => 'freezer', 'quantity' => '0,5', 'unit' => 'kg'])
        ->assertSessionHasNoErrors();
    $this->post(route('inventory.store'), ['ingredient' => 'Pollo', 'location' => 'fridge', 'quantity' => '200', 'unit' => 'g'])
        ->assertSessionHasNoErrors();

    $rows = InventoryItem::query()->orderBy('id')->get()->map(fn ($row) => $row->location->value.' '.$row->toQuantity()->format())->all();
    expect($rows)->toBe(['freezer 1 kg', 'fridge 200 g']);
});

test('regression: classifying from Inventory saves the category and keeps the name and usual unit', function () {
    $sal = Ingredient::factory()->for($this->household)->named('Sal')->create(['default_unit' => Unit::Gram]);
    $azucar = Ingredient::factory()->for($this->household)->named('Azúcar')->create();

    // What the «Clasificar alimentos» dialog sends: the current name and unit plus the category.
    $this->from(route('inventory.index'))
        ->put(route('ingredients.update', $sal), ['name' => 'Sal', 'default_unit' => 'g', 'category' => 'sauces_oils'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('inventory.index'));
    // Keeping one without a category is a valid choice.
    $this->put(route('ingredients.update', $azucar), ['name' => 'Azúcar', 'default_unit' => null, 'category' => ''])
        ->assertSessionHasNoErrors();

    expect($sal->fresh())
        ->category->toBe(IngredientCategory::SaucesOils)
        ->name->toBe('Sal')
        ->default_unit->toBe(Unit::Gram)
        ->and($azucar->fresh()->category)->toBeNull();

    $this->get(route('inventory.index'))->assertInertia(fn (Assert $page) => $page
        ->where('foods.0.name', 'Azúcar')
        ->where('foods.0.category', null)
        ->where('foods.1.category', 'sauces_oils')
        ->where('foods.1.category_label', 'Salsas, aceites y condimentos'));
});
