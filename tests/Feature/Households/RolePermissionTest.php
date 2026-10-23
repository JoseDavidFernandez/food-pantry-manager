<?php

use App\Enums\HouseholdRole;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RN-38: the permissions of admin, editor and viewer, checked on the server for every route.
 */
beforeEach(function () {
    $this->travelTo('2026-10-19 08:00:00');
    $this->admin = User::factory()->withHousehold()->create();
    $this->household = $this->admin->currentHousehold();
    $this->editor = User::factory()->memberOf($this->household, HouseholdRole::Editor)->create();
    $this->viewer = User::factory()->memberOf($this->household, HouseholdRole::Viewer)->create();

    // Real data of the household, so every route reaches its authorization with valid ids.
    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $this->alias = $this->arroz->aliases()->create(['household_id' => $this->household->id, 'alias' => 'Arroz largo', 'normalized_alias' => 'arroz largo']);
    $this->conversion = $this->arroz->conversions()->create(['unit' => 'bag', 'factor' => '1000', 'to_unit' => 'g']);
    $this->stock = InventoryItem::factory()->for($this->arroz)->of('100', Unit::Gram, StorageLocation::Pantry)->create();
    $this->recipe = Recipe::factory()->for($this->household)->withIngredients([[$this->arroz, Quantity::of('600', Unit::Gram)]])->create(['name' => 'Paella']);
    $this->actingAs($this->admin)->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch']), [
        'type' => 'recipe', 'recipe_id' => $this->recipe->id, 'servings' => 2,
    ])->assertSessionHasNoErrors();
    $this->get(route('shopping-list.period'))->assertOk();
    $this->list = ShoppingList::sole();
    $this->item = $this->list->items()->sole();
});

/**
 * Every route of the household data that changes something, with the ids of this household.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function writeRoutes(): array
{
    $ids = [
        '{ingredient}' => test()->arroz->id, '{alias}' => test()->alias->id, '{inventory}' => test()->stock->id,
        '{recipe}' => test()->recipe->id, '{week}' => '2026-10-19', '{day}' => '1', '{meal}' => 'lunch',
        '{list}' => test()->list->id, '{item}' => test()->item->id, '{conversion}' => test()->conversion->id,
        '{dish}' => dishIn(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch']),
    ];

    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => in_array('household.write', $route->gatherMiddleware(), true))
        ->flatMap(fn (Route $route) => collect($route->methods())
            ->reject(fn (string $method) => in_array($method, ['GET', 'HEAD'], true))
            ->map(fn (string $method) => [$method, '/'.strtr($route->uri(), $ids), (string) $route->getName()]))
        ->values()
        ->all();
}

/**
 * A fingerprint of everything the household owns, to prove nothing changed.
 */
function householdData(Household $household): string
{
    $tables = ['ingredients', 'ingredient_aliases', 'inventory_items', 'recipes', 'menus', 'shopping_lists', 'receptions', 'household_invitations'];

    return md5(json_encode(array_map(fn (string $table) => DB::table($table)->where('household_id', $household->id)->orderBy('id')->get(), $tables))
        .json_encode(DB::table('planned_dishes')->orderBy('id')->get())
        .json_encode(DB::table('ingredient_conversions')->orderBy('id')->get())
        .json_encode(DB::table('ingredients')->orderBy('id')->pluck('purchase_unit'))
        .json_encode(DB::table('shopping_list_items')->orderBy('id')->get())
        .json_encode(DB::table('household_user')->orderBy('id')->get()));
}

test('the sweep covers every write route of the household data', function () {
    $names = array_column(writeRoutes(), 2);

    expect(count($names))->toBeGreaterThanOrEqual(25)
        ->and($names)->toContain(
            'inventory.store', 'inventory.adjust', 'ingredients.update', 'ingredients.aliases.store', 'recipes.store',
            'menu.dishes.store', 'menu.dishes.update', 'menu.dishes.move', 'menu.dishes.position', 'menu.dishes.eaten', 'shopping-lists.items.purchased',
            'shopping-lists.receptions.store', 'ingredients.image.store',
            'ingredients.conversions.store', 'ingredients.conversions.destroy', 'ingredients.purchase-unit',
            'shopping-lists.receptions.checked', 'inventory.destroy', 'inventory.update',
            'ingredients.minimum', 'ingredients.depleted',
        );
});

test('CA-24, RN-38: a viewer gets 403 on every write route, by direct request, and nothing changes', function () {
    $before = householdData($this->household);
    $this->actingAs($this->viewer);

    foreach (writeRoutes() as [$method, $uri, $name]) {
        $this->call($method, $uri, ['name' => 'X', 'quantity' => '1', 'unit' => 'kg', 'eaten' => '0', 'to_day' => 2, 'to_meal' => 'dinner'])
            ->assertStatus(403, "{$method} {$uri} ({$name})");
    }

    expect(householdData($this->household))->toBe($before);
});

test('CA-24, RN-38: from the app, a viewer is told the action is not allowed', function () {
    $this->actingAs($this->viewer)
        ->withHeader('X-Inertia', 'true')
        ->from(route('inventory.index'))
        ->post(route('inventory.store'), ['ingredient' => 'Arroz', 'location' => 'pantry', 'quantity' => '1', 'unit' => 'kg'])
        ->assertRedirect(route('inventory.index'))
        ->assertInertiaFlash('toast.message', 'Tu rol en el hogar es de lectura: no puedes modificar datos.');

    expect(InventoryItem::count())->toBe(1);
});

test('CA-24, RN-38: a viewer reads every page of the household', function () {
    $this->actingAs($this->viewer);

    foreach ([
        route('dashboard'), route('inventory.index'), route('inventory.index', ['location' => 'none']), route('recipes.index'),
        route('recipes.show', $this->recipe), route('menu.show', '2026-10-19'), route('shopping-list.period'),
        route('shopping-list.period', ['days' => 30]), route('shopping-lists.index'), route('household.show'),
        route('ingredients.image.show', $this->arroz),
    ] as $url) {
        $status = $this->get($url)->status();
        expect($status === 200 || ($url === route('ingredients.image.show', $this->arroz) && $status === 404))->toBeTrue("GET {$url}: {$status}");
    }

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.household.role', 'viewer')
        ->where('auth.household.can', ['write' => false, 'manage' => false]));

    // Pages that only exist to change data send the viewer back with a message.
    $this->get(route('recipes.create'))->assertRedirect(route('recipes.index'))->assertInertiaFlash('toast.type', 'error');
    $this->get(route('recipes.edit', $this->recipe))->assertRedirect(route('recipes.index'));
    $this->get(route('shopping-lists.receptions.create', $this->list))->assertRedirect()->assertInertiaFlash('toast.type', 'error');
    // The image search (external services) is only for who can edit.
    $this->getJson(route('ingredients.images.search', ['source' => 'photos', 'q' => 'arroz']))->assertForbidden();
});

test('CA-24, RN-38: an editor changes the household data on every write route', function () {
    $this->actingAs($this->editor);

    foreach (writeRoutes() as [$method, $uri, $name]) {
        expect($this->call($method, $uri, ['name' => 'X'])->status())->not->toBe(403, "{$method} {$uri} ({$name})");
    }

    $this->post(route('inventory.store'), ['ingredient' => 'Arroz', 'location' => 'fridge', 'quantity' => '1', 'unit' => 'kg'])->assertSessionHasNoErrors();
    expect(InventoryItem::where('location', 'fridge')->count())->toBe(1);
});

test('CA-24, RN-38: editors and viewers cannot do anything administrative', function () {
    $invitation = $this->actingAs($this->admin)->post(route('household.invitations.store'), ['email' => 'x@example.com', 'role' => 'viewer']);
    $invitation = HouseholdInvitation::sole();
    $before = householdData($this->household);

    foreach ([$this->editor, $this->viewer] as $member) {
        $this->actingAs($member);
        $this->patch(route('household.update'), ['name' => 'Otro'])->assertForbidden();
        $this->post(route('household.invitations.store'), ['email' => 'y@example.com', 'role' => 'admin'])->assertForbidden();
        $this->delete(route('household.invitations.destroy', $invitation))->assertForbidden();
        // Nobody raises their own role, nor changes or removes others.
        $this->patch(route('household.members.update', $member), ['role' => 'admin'])->assertForbidden();
        $this->patch(route('household.members.update', $this->admin), ['role' => 'viewer'])->assertForbidden();
        $this->delete(route('household.members.destroy', $this->admin))->assertForbidden();
    }

    expect(householdData($this->household))->toBe($before)
        ->and($this->household->fresh()->name)->not->toBe('Otro');
});

test('CA-24, RN-38: the admin manages the household: name, roles and members', function () {
    $this->actingAs($this->admin);

    $this->patch(route('household.update'), ['name' => 'Casa'])->assertSessionHasNoErrors();
    $this->patch(route('household.members.update', $this->viewer), ['role' => 'editor'])->assertInertiaFlash('toast.type', 'success');
    $this->delete(route('household.members.destroy', $this->editor))->assertInertiaFlash('toast.type', 'success');

    expect($this->household->fresh()->name)->toBe('Casa')
        ->and($this->viewer->householdRole())->toBe(HouseholdRole::Editor)
        ->and($this->editor->hasHousehold())->toBeFalse();

    // The removed member goes back to the household setup; the data stays in the household.
    $this->actingAs($this->editor)->get(route('dashboard'))->assertRedirect(route('household.setup'));
    expect(Recipe::where('household_id', $this->household->id)->count())->toBe(1);
});

test('CA-24: the last admin cannot be demoted, removed or leave; with another admin it can', function () {
    $this->actingAs($this->admin);

    $this->patch(route('household.members.update', $this->admin), ['role' => 'editor'])
        ->assertInertiaFlash('toast.message', 'El hogar necesita al menos un administrador. Nombra antes a otro miembro administrador.');
    $this->post(route('household.leave'))->assertInertiaFlash('toast.message', 'Eres el único administrador. Nombra antes a otro miembro administrador para poder salir.');
    $this->delete(route('household.members.destroy', $this->admin))->assertInertiaFlash('toast.type', 'error');
    $this->delete(route('profile.destroy'), ['password' => 'password'])->assertInertiaFlash('toast.type', 'error');
    expect($this->admin->householdRole())->toBe(HouseholdRole::Admin)
        ->and(User::whereKey($this->admin->id)->exists())->toBeTrue();

    // With a second admin, the first one can step down or leave.
    $this->patch(route('household.members.update', $this->editor), ['role' => 'admin']);
    $this->patch(route('household.members.update', $this->admin), ['role' => 'editor'])->assertInertiaFlash('toast.type', 'success');
    expect($this->admin->householdRole())->toBe(HouseholdRole::Editor);
});

test('leaving: a member leaves and must create or join a household; the only member cannot leave', function () {
    $this->actingAs($this->viewer)->post(route('household.leave'))->assertRedirect(route('household.setup'));
    expect($this->viewer->hasHousehold())->toBeFalse();
    $this->get(route('inventory.index'))->assertRedirect(route('household.setup'));

    $alone = User::factory()->withHousehold()->create();
    Ingredient::factory()->for($alone->currentHousehold())->named('Sal')->create();
    $this->actingAs($alone)->post(route('household.leave'))
        ->assertInertiaFlash('toast.message', 'Eres el único miembro y el hogar tiene datos: no puedes salir de él. Eliminar un hogar con datos no está disponible en esta versión.');
    expect($alone->hasHousehold())->toBeTrue();
});

test('ids of other households and payloads cannot reach another household', function () {
    $other = User::factory()->withHousehold()->create();
    $otherHousehold = $other->currentHousehold();
    $this->actingAs($other)->post(route('household.invitations.store'), ['email' => 'z@example.com', 'role' => 'editor']);
    $foreignInvitation = HouseholdInvitation::where('household_id', $otherHousehold->id)->sole();

    $this->actingAs($this->admin);
    $this->patch(route('household.members.update', $other), ['role' => 'viewer'])->assertNotFound();
    $this->delete(route('household.members.destroy', $other))->assertNotFound();
    $this->delete(route('household.invitations.destroy', $foreignInvitation))->assertNotFound();

    // A household_id in the payload is ignored: the household is always the user's own.
    $this->post(route('inventory.store'), ['ingredient' => 'Sal', 'create_ingredient' => '1', 'household_id' => $otherHousehold->id, 'location' => 'pantry', 'quantity' => '1', 'unit' => 'kg']);
    expect(Ingredient::where('name', 'Sal')->sole()->household_id)->toBe($this->household->id)
        ->and($other->householdRole())->toBe(HouseholdRole::Admin)
        ->and($foreignInvitation->fresh()->status())->toBe(HouseholdInvitation::PENDING);
});
