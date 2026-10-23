<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Quantity;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Monday morning of the planned week: its dishes are still pending (RN-44).
    atMadrid('2026-10-19 08:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $this->pollo = Ingredient::factory()->for($this->household)->named('Contramuslos de pollo')->create();
    $this->tomate = Ingredient::factory()->for($this->household)->named('Tomate frito')->create();

    // "Pollo con arroz" for 4 servings.
    $this->recipe = Recipe::factory()->for($this->household)
        ->withIngredients([
            [$this->arroz, Quantity::of('320', Unit::Gram)],
            [$this->pollo, Quantity::of('4', Unit::Piece)],
            [$this->tomate, Quantity::of('200', Unit::Gram)],
        ])
        ->create(['name' => 'Pollo con arroz', 'base_servings' => 4]);
});

function requirementLines(): array
{
    $lines = [];

    test()->get(route('menu.show', '2026-10-19'))
        ->assertInertia(function (Assert $page) use (&$lines) {
            $lines = collect($page->toArray()['props']['requirements']['lines'])->keyBy('ingredient')->all();
        });

    return $lines;
}

test('Pollo con arroz on Monday lunch, scaled to 6 servings, compared with the inventory', function () {
    InventoryItem::factory()->for($this->arroz)->of('1', Unit::Kilogram, StorageLocation::Pantry)->create();
    InventoryItem::factory()->for($this->pollo)->of('4', Unit::Piece, StorageLocation::Fridge)->create();
    InventoryItem::factory()->for($this->tomate)->of('2', Unit::Jar, StorageLocation::Pantry)->create();

    planInSlot(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 6,
    ])->assertSessionHasNoErrors();

    $lines = requirementLines();

    // CA-05: 320 g × 6/4 = 480 g; 1 kg in the pantry is enough.
    expect($lines['Arroz'])->toMatchArray(['need' => '480 g', 'have' => '1 kg', 'missing' => null, 'status' => 'enough'])
        // 4 × 6/4 = 6 pieces; 4 in the fridge, 2 missing.
        ->and($lines['Contramuslos de pollo'])->toMatchArray(['need' => '6 unidades', 'have' => '4 unidades', 'missing' => '2 unidades', 'status' => 'short'])
        // RN-22: jars cannot be converted to grams; flagged for review, never subtracted.
        ->and($lines['Tomate frito'])->toMatchArray(['need' => '300 g', 'have' => null, 'status' => 'review'])
        ->and($lines['Arroz']['dishes'])->toBe(['Pollo con arroz']);
});

test('needs of several dishes are combined (g + kg) and stock of both locations is added', function () {
    InventoryItem::factory()->for($this->arroz)->of('200', Unit::Gram, StorageLocation::Pantry)->create();
    InventoryItem::factory()->for($this->arroz)->of('0.1', Unit::Kilogram, StorageLocation::Fridge)->create();

    planInSlot(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'], [
        'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 4,
    ]);
    planInSlot(['week' => '2026-10-19', 'day' => 2, 'meal' => 'dinner'], [
        'type' => 'adhoc', 'name' => 'Arroz blanco', 'servings' => 2,
        'ingredients' => [['ingredient' => 'Arroz', 'quantity' => '0,7', 'unit' => 'kg']],
    ]);

    $lines = requirementLines();

    // 320 g + 0.7 kg = 1.02 kg; stock 200 g + 0.1 kg = 300 g; missing 720 g.
    expect($lines['Arroz'])->toMatchArray(['need' => '1,02 kg', 'have' => '300 g', 'missing' => '720 g', 'status' => 'short'])
        ->and($lines['Arroz']['dishes'])->toBe(['Pollo con arroz', 'Arroz blanco'])
        ->and($lines['Contramuslos de pollo'])->toMatchArray(['have' => null, 'missing' => '4 unidades', 'status' => 'missing']);
});

test('an empty week has no requirements', function () {
    $this->get(route('menu.show', '2026-10-19'))
        ->assertInertia(fn (Assert $page) => $page->where('requirements.lines', [])->where('requirements.incomplete', []));
});
