<?php

use App\Actions\Ingredients\IngredientCatalog;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->catalog = app(IngredientCatalog::class);
    $this->actingAs($this->user);

    $this->tomate = Ingredient::factory()->for($this->household)->named('Tomate')->create();
});

function similarNames(string $text): array
{
    return array_column(test()->catalog->similar(test()->household, $text), 'name');
}

test('RN-03: case, accents and extra spaces resolve to the same ingredient', function (string $typed) {
    expect($this->catalog->resolve($this->household, $typed)?->id)->toBe($this->tomate->id);
})->with(['tomate', 'TOMATE', 'Tomáte', '  tomate  ', 'TOMÁTE ']);

test('"Tome" is not resolved automatically, only suggested', function () {
    expect($this->catalog->resolve($this->household, 'Tome'))->toBeNull()
        ->and(similarNames('Tome'))->toBe(['Tomate']);
});

test('RN-04: "Tomates" is not merged automatically; it is suggested until an alias exists', function () {
    expect($this->catalog->resolve($this->household, 'Tomates'))->toBeNull()
        ->and(similarNames('Tomates'))->toBe(['Tomate']);

    $this->catalog->addAlias($this->tomate, 'Tomates');

    expect($this->catalog->resolve($this->household, 'tomates')?->id)->toBe($this->tomate->id);
});

test('unrelated names produce no suggestions', function (string $typed) {
    Ingredient::factory()->for($this->household)->named('Huevos')->create();

    expect(similarNames($typed))->toBe([]);
})->with(['Tomillo', 'Leche', 'Lentejas', 'Té']);

test('suggestions also match aliases and report them', function () {
    $this->catalog->addAlias($this->tomate, 'Jitomate');

    expect($this->catalog->similar($this->household, 'Jitomates')[0])
        ->toMatchArray(['name' => 'Tomate', 'via' => 'Jitomate']);
});

test('adding stock for "Tome" is rejected with a suggestion and nothing is assigned', function () {
    $this->from(route('inventory.index'))
        ->post(route('inventory.store'), ['ingredient' => 'Tome', 'quantity' => '2', 'unit' => 'piece', 'location' => 'fridge'])
        ->assertSessionHasErrors(['ingredient' => '«Tome» no está en tu catálogo. ¿Querías decir «Tomate»? Elige una sugerencia o marca «Crear el ingrediente».'], errorBag: 'default');

    expect(InventoryItem::count())->toBe(0);

    $this->get(route('inventory.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('typed', 'Tome')
            ->where('similar.0.name', 'Tomate'));
});

test('creating a similar ingredient from the inventory needs an explicit confirmation', function () {
    $data = ['ingredient' => 'Tome', 'quantity' => '2', 'unit' => 'piece', 'location' => 'fridge', 'create_ingredient' => '1'];

    $this->post(route('inventory.store'), $data)->assertSessionHasErrors('ingredient');
    expect($this->household->ingredients()->count())->toBe(1);

    $this->post(route('inventory.store'), [...$data, 'confirm_new' => '1'])->assertSessionHasNoErrors();
    expect($this->household->ingredients()->pluck('name')->sort()->values()->all())->toBe(['Tomate', 'Tome']);
});

test('choosing the suggestion adds stock to the existing ingredient', function () {
    $this->post(route('inventory.store'), ['ingredient' => 'Tomate', 'quantity' => '2', 'unit' => 'piece', 'location' => 'fridge'])
        ->assertSessionHasNoErrors();

    expect(InventoryItem::sole()->ingredient_id)->toBe($this->tomate->id);
});

test('adding "Tomates" to the inventory warns about "Tomate" and offers it as alias', function () {
    $this->from(route('inventory.index'))
        ->post(route('inventory.store'), ['ingredient' => 'Tomates', 'quantity' => '2', 'unit' => 'piece', 'location' => 'fridge'])
        ->assertSessionHasErrors('ingredient');

    $this->get(route('inventory.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('typed', 'Tomates')
            ->where('similar.0.name', 'Tomate'));

    $this->post(route('ingredients.aliases.store', $this->tomate), ['alias' => 'Tomates'])->assertSessionHasNoErrors();

    expect($this->household->ingredients()->count())->toBe(1)
        ->and($this->catalog->resolve($this->household, 'Tomates')?->id)->toBe($this->tomate->id);
});

test('a similar but different ingredient can be created after confirming', function () {
    $this->post(route('ingredients.store'), ['name' => 'Tomate pera', 'intent' => 'create', 'confirm_new' => '1'])
        ->assertSessionHasNoErrors();

    expect($this->household->ingredients()->count())->toBe(2);
});

test('renaming does not ask for similarity confirmation', function () {
    $this->put(route('ingredients.update', $this->tomate), ['name' => 'Tomates'])->assertSessionHasNoErrors();

    expect($this->tomate->fresh()->name)->toBe('Tomates');
});
