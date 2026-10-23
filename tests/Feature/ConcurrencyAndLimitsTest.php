<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

const RACE_WEEK = '2026-10-19';

beforeEach(function () {
    // Monday 19 Oct 2026 in Madrid: the week is the current period (RN-36).
    $this->travelTo('2026-10-19 08:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
});

/**
 * Runs $interleave once, right after the first query that matches $pattern: what another request
 * would do between this request reading the data and writing it.
 */
function interleaveAfter(string $pattern, Closure $interleave): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, $pattern, $interleave) {
        if (! $done && preg_match($pattern, $query->sql) === 1) {
            $done = true;
            $interleave();
        }
    });
}

function openRaceList(): mixed
{
    return test()->get(route('shopping-list.period', ['start' => RACE_WEEK, 'end' => '2026-10-25']));
}

function raceListId(): int
{
    return (int) ShoppingList::query()->whereDate('period_start', RACE_WEEK)->value('id');
}

function raceDish(int $day, string $meal, string $quantity = '300'): mixed
{
    return planInSlot(['week' => RACE_WEEK, 'day' => $day, 'meal' => $meal], [
        'type' => 'adhoc', 'name' => 'Plato', 'servings' => 2,
        'ingredients' => [['ingredient' => 'Arroz', 'quantity' => $quantity, 'unit' => 'g']],
    ]);
}

test('CA-10, scenario 1: two requests opening a new period at once end in a single list (unique household + period)', function () {
    raceDish(1, 'lunch')->assertSessionHasNoErrors();

    // The other request creates the list right after this one looked for it and found nothing.
    interleaveAfter('/from `shopping_lists`/', fn () => DB::table('shopping_lists')->insert([
        'household_id' => $this->household->id, 'period_start' => RACE_WEEK, 'period_end' => '2026-10-25',
        'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));

    openRaceList()->assertOk();

    expect(ShoppingList::count())->toBe(1)
        ->and(ShoppingListItem::sole()->toQuantity()->format())->toBe('300 g');
});

test('scenario 2: adding stock above the storable maximum is rejected without touching the row', function () {
    InventoryItem::factory()->for($this->arroz)->of('999999999', Unit::Gram, StorageLocation::Pantry)->create();

    $this->post(route('inventory.store'), ['ingredient' => 'Arroz', 'location' => 'pantry', 'quantity' => '999999999', 'unit' => 'g'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect(InventoryItem::sole()->toQuantity()->format())->toBe('999999999 g');
});

test('scenario 2: needs above the storable maximum are rejected and add no line to the list', function () {
    raceDish(1, 'lunch', '999999999');
    raceDish(1, 'dinner', '999999999');

    openRaceList()
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect(ShoppingListItem::count())->toBe(0);
});

test('scenario 2: merging into a list line above the storable maximum is rejected', function () {
    raceDish(1, 'lunch', '999999999');
    openRaceList()->assertOk();

    $this->post(route('shopping-lists.items.store', raceListId()), ['item' => 'Arroz', 'quantity' => '999999999', 'unit' => 'g'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect(ShoppingListItem::sole()->toQuantity()->format())->toBe('999999999 g');
});

test('scenario 3: two simultaneous adds of the same ingredient end in one merged line, not an error', function () {
    raceDish(1, 'lunch');
    openRaceList()->assertOk();
    $list = ShoppingListItem::sole()->shoppingList;
    $leche = Ingredient::factory()->for($this->household)->named('Leche')->create();

    // The other request inserts the same ingredient right after this one looked for it.
    interleaveAfter('/from `shopping_list_items`.*`match_key`/', fn () => DB::table('shopping_list_items')->insert([
        'shopping_list_id' => $list->id, 'origin' => ShoppingListItem::MANUAL, 'ingredient_id' => $leche->id,
        'name' => 'Leche', 'quantity' => '1.000', 'unit' => 'l', 'unit_group' => 'volume',
        'match_key' => $leche->id.':volume', 'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->post(route('shopping-lists.items.store', $list->id), ['item' => 'Leche', 'quantity' => '2', 'unit' => 'l'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $lines = ShoppingListItem::where('ingredient_id', $leche->id)->get();
    expect($lines)->toHaveCount(1)
        ->and($lines->sole()->toQuantity()->format())->toBe('3 l');
});

test('scenario 1b: two requests opening «Próximos 7 días» at once end in a single moving list', function () {
    raceDish(1, 'lunch')->assertSessionHasNoErrors();

    // The other request creates the quick list right after this one looked for it.
    interleaveAfter('/`rolling_days` = \?/', fn () => DB::table('shopping_lists')->insert([
        'household_id' => $this->household->id, 'period_start' => RACE_WEEK, 'period_end' => '2026-10-25',
        'rolling_days' => 7, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->get(route('shopping-list.period', ['days' => 7]))->assertOk();

    expect(ShoppingList::count())->toBe(1)
        ->and(ShoppingList::sole()->rolling_days)->toBe(7)
        ->and(ShoppingListItem::sole()->toQuantity()->format())->toBe('300 g');
});
