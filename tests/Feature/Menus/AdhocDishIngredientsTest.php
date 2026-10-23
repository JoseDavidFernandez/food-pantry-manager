<?php

use App\Actions\Ingredients\CategoryGuesser;
use App\Actions\Ingredients\IngredientCatalog;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\PlannedDish;
use App\Models\Recipe;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->pan = Ingredient::factory()->for($this->household)->named('Pan')->create();
    $this->quesoRallado = Ingredient::factory()->for($this->household)->named('Queso rallado')->create();
});

const SANDWICH_SLOT = ['week' => '2026-10-19', 'day' => 2, 'meal' => 'dinner'];

/**
 * @param  list<array<string, string>>  $ingredients
 */
function saveSandwich(array $ingredients): mixed
{
    return planInSlot(SANDWICH_SLOT, [
        'type' => 'adhoc', 'name' => 'Sandwich Mixto', 'servings' => 2, 'ingredients' => $ingredients,
    ]);
}

/**
 * @return list<string> "ingredient name: quantity" of the saved dish
 */
function sandwichLines(): array
{
    return PlannedDish::query()->sole()->ingredients()->with('ingredient')->orderBy('position')->get()
        ->map(fn ($line) => $line->ingredient->name.': '.$line->toQuantity()->format())
        ->all();
}

test('names that are not in the catalog are rejected with their suggestions, and nothing is created', function () {
    saveSandwich([
        ['ingredient' => 'Pan de molde', 'quantity' => '4', 'unit' => 'slice'],
        ['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice'],
        ['ingredient' => 'Queso', 'quantity' => '2', 'unit' => 'deli_slice'],
    ])->assertSessionHasErrors([
        'ingredients.0.ingredient' => '«Pan de molde» no está en tu catálogo. ¿Querías decir «Pan»? Elige la sugerencia, corrige el nombre o créalo como ingrediente nuevo.',
        'ingredients.1.ingredient' => '«Jamón de York» no está en tu catálogo. Corrige el nombre o créalo como ingrediente nuevo.',
        'ingredients.2.ingredient' => '«Queso» no está en tu catálogo. ¿Querías decir «Queso rallado»? Elige la sugerencia, corrige el nombre o créalo como ingrediente nuevo.',
    ], errorBag: 'default')
        // Structured suggestions per row, so the form can offer them as buttons.
        ->assertInertiaFlash('ingredientSuggestions.0', ['Pan'])
        ->assertInertiaFlash('ingredientSuggestions.1', [])
        ->assertInertiaFlash('ingredientSuggestions.2', ['Queso rallado']);

    // A suggestion is never applied on its own (RN-04).
    expect(PlannedDish::count())->toBe(0)
        ->and($this->household->ingredients()->count())->toBe(2)
        ->and($this->household->ingredientAliases()->count())->toBe(0);
});

test('the user can create the new ingredients from the dish form, each one linked to its line', function () {
    saveSandwich([
        ['ingredient' => 'Pan de molde', 'quantity' => '4', 'unit' => 'slice', 'create' => '1'],
        ['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1'],
        ['ingredient' => 'Queso', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1'],
    ])->assertSessionHasNoErrors();

    expect(sandwichLines())->toBe(['Pan de molde: 4 rebanadas', 'Jamón de York: 2 lonchas', 'Queso: 2 lonchas'])
        ->and($this->household->ingredients()->orderBy('name')->pluck('name')->all())
        ->toBe(['Jamón de York', 'Pan', 'Pan de molde', 'Queso', 'Queso rallado'])
        // Existing similar ingredients are untouched and no alias is created.
        ->and($this->household->ingredientAliases()->count())->toBe(0)
        ->and(PlannedDish::sole()->ingredients()->pluck('ingredient_id')->all())->not->toContain($this->pan->id, $this->quesoRallado->id);
});

test('the user can choose the suggested existing ingredient instead', function () {
    saveSandwich([
        ['ingredient' => 'Pan', 'quantity' => '2', 'unit' => 'piece'],
        ['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1'],
        ['ingredient' => 'queso rallado', 'quantity' => '30', 'unit' => 'g'],
    ])->assertSessionHasNoErrors();

    expect(PlannedDish::sole()->ingredients()->pluck('ingredient_id')->all())
        ->toContain($this->pan->id, $this->quesoRallado->id)
        ->and($this->household->ingredients()->count())->toBe(3);
});

test('a name that already exists, ignoring case, accents and spaces, is reused even when asked to create it', function () {
    $jamon = Ingredient::factory()->for($this->household)->named('Jamón de York')->create();

    saveSandwich([['ingredient' => '  JAMON   de york ', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1']])
        ->assertSessionHasNoErrors();

    expect(PlannedDish::sole()->ingredients()->sole()->ingredient_id)->toBe($jamon->id)
        ->and($this->household->ingredients()->count())->toBe(3);
});

test('an explicit alias is resolved to its ingredient and never duplicated', function () {
    $jamon = Ingredient::factory()->for($this->household)->named('Jamón de York')->create();
    app(IngredientCatalog::class)->addAlias($jamon, 'Jamón cocido');

    saveSandwich([['ingredient' => 'jamon cocido', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1']])
        ->assertSessionHasNoErrors();

    expect(PlannedDish::sole()->ingredients()->sole()->ingredient_id)->toBe($jamon->id)
        ->and($this->household->ingredients()->count())->toBe(3);
});

test('the same new ingredient cannot be written twice with compatible units in one dish', function () {
    saveSandwich([
        ['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1'],
        ['ingredient' => 'jamon de york', 'quantity' => '1', 'unit' => 'deli_slice', 'create' => '1'],
    ])->assertSessionHasErrors('ingredients.1.ingredient');

    expect($this->household->ingredients()->count())->toBe(2);
});

test('when another request created the same name first, the existing row is reused', function () {
    $jamon = Ingredient::factory()->for($this->household)->named('Jamón de York')->create();

    // Simulates the race: the name was not found when checking, but exists when inserting.
    $catalog = new class(new CategoryGuesser) extends IngredientCatalog
    {
        public function resolve(Household $household, string $text): ?Ingredient
        {
            return null;
        }
    };

    expect($catalog->findOrCreate($this->household, 'jamón de york')->id)->toBe($jamon->id)
        ->and($this->household->ingredients()->where('normalized_name', 'jamon de york')->count())->toBe(1);
});

test('nothing is created when another field of the dish is invalid', function () {
    planInSlot(SANDWICH_SLOT, [
        'type' => 'adhoc', 'name' => 'Sandwich Mixto', 'servings' => 0,
        'ingredients' => [['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1']],
    ])->assertSessionHasErrors('servings');

    expect($this->household->ingredients()->count())->toBe(2)
        ->and(PlannedDish::count())->toBe(0);
});

test('a dish without ingredients can still be saved, but stays incomplete and adds nothing to buy', function () {
    $this->travelTo('2026-10-19 08:00:00');
    saveSandwich([])->assertSessionHasNoErrors();

    $this->get(route('menu.show', '2026-10-19'))
        ->assertInertia(fn (Assert $page) => $page->where('requirements.incomplete.0.dish', 'Sandwich Mixto'));
    $this->get(route('shopping-list.period', ['start' => '2026-10-19', 'end' => '2026-10-25']))
        ->assertInertia(fn (Assert $page) => $page->where('items', []));
});

test('recipes can also create their new ingredients explicitly', function () {
    $this->post(route('recipes.store'), [
        'name' => 'Sandwich de la casa', 'base_servings' => '1',
        'ingredients' => [
            ['ingredient' => 'Pan de molde', 'quantity' => '2', 'unit' => 'slice', 'create' => '1'],
            ['ingredient' => 'Pan', 'quantity' => '1', 'unit' => 'piece'],
        ],
    ])->assertSessionHasNoErrors();

    $recipe = Recipe::sole();
    expect($recipe->ingredients()->with('ingredient')->get()->map(fn ($line) => $line->ingredient->name)->all())
        ->toBe(['Pan de molde', 'Pan']);
});

test('ingredients are created in the user household only and never resolved from another household', function () {
    $other = User::factory()->withHousehold()->create();
    $foreign = Ingredient::factory()->for($other->currentHousehold())->named('Jamón de York')->create();

    // Without asking to create it, another household's ingredient is not «in your catalog».
    saveSandwich([['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice']])
        ->assertSessionHasErrors('ingredients.0.ingredient');

    saveSandwich([['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1']])
        ->assertSessionHasNoErrors();

    $mine = $this->household->ingredients()->where('name', 'Jamón de York')->sole();
    expect($mine->id)->not->toBe($foreign->id)
        ->and(PlannedDish::sole()->ingredients()->sole()->ingredient_id)->toBe($mine->id)
        ->and($other->currentHousehold()->ingredients()->count())->toBe(1);
});

test('guests cannot save dishes or create ingredients', function () {
    auth()->logout();

    saveSandwich([['ingredient' => 'Jamón de York', 'quantity' => '2', 'unit' => 'deli_slice', 'create' => '1']])
        ->assertRedirect(route('login'));

    expect(Ingredient::where('name', 'Jamón de York')->exists())->toBeFalse();
});
