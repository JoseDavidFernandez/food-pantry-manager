<?php

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\Menu;
use App\Models\Reception;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\Week;
use Database\Seeders\ManualTestDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A household with stock, a menu, a list and a reception, created through the app.
 */
function householdWithHistory(): User
{
    test()->travelTo('2026-10-19 08:00:00');
    $user = User::factory()->withHousehold()->create();
    test()->actingAs($user);

    $rice = Ingredient::factory()->for($user->currentHousehold())->named('Arroz')->create();
    InventoryItem::factory()->for($rice)->of('100', Unit::Gram, StorageLocation::Pantry)->create();

    planInSlot(['week' => '2026-10-19', 'day' => 1, 'meal' => 'lunch'], [
        'type' => 'adhoc', 'name' => 'Arroz', 'servings' => 2, 'ingredients' => [['ingredient' => 'Arroz', 'quantity' => '300', 'unit' => 'g']],
    ]);
    test()->get(route('shopping-list.period', ['start' => '2026-10-19', 'end' => '2026-10-25']))->assertOk();
    $listId = ShoppingList::where('household_id', $user->currentHousehold()->id)->sole()->id;
    test()->post(route('shopping-lists.receptions.store', $listId), ['token' => (string) Str::uuid(), 'lines' => [
        ['item_id' => ShoppingList::where('household_id', $user->currentHousehold()->id)->sole()->items()->sole()->id, 'outcome' => 'received', 'quantity' => '200', 'unit' => 'g', 'location' => 'pantry'],
    ]])->assertSessionHasNoErrors();

    return $user;
}

/**
 * @return array<string, int>
 */
function householdFootprint(Household $household): array
{
    return [
        'ingredients' => $household->ingredients()->count(),
        'inventory' => $household->inventoryItems()->count(),
        'menus' => $household->menus()->count(),
        'lists' => ShoppingList::where('household_id', $household->id)->count(),
        'receptions' => Reception::where('household_id', $household->id)->count(),
    ];
}

function runManualSeeder(Household $household, bool $dryRun = false): void
{
    putenv('SEED_HOUSEHOLD_ID='.$household->id);
    putenv('SEED_DRY_RUN='.($dryRun ? '1' : '0'));
    app()->detectEnvironment(fn () => 'local');

    try {
        test()->seed(ManualTestDataSeeder::class);
    } finally {
        putenv('SEED_HOUSEHOLD_ID');
        putenv('SEED_DRY_RUN');
        app()->detectEnvironment(fn () => 'testing');
    }
}

test('the seeder replaces the data of the chosen household only', function () {
    $target = householdWithHistory()->currentHousehold();
    $other = householdWithHistory()->currentHousehold();
    $before = householdFootprint($other);
    $usersBefore = User::count();

    $this->travelTo('2026-10-20 10:00:00');
    runManualSeeder($target);

    expect(householdFootprint($other))->toBe($before)
        ->and(User::count())->toBe($usersBefore)
        ->and(Household::count())->toBe(2)
        ->and(householdFootprint($target))->toBe(['ingredients' => 23, 'inventory' => 19, 'menus' => 1, 'lists' => 0, 'receptions' => 0])
        ->and($target->recipes()->count())->toBe(6)
        ->and($target->ingredientAliases()->pluck('alias')->sort()->values()->all())->toBe(['Huevo', 'Patatas']);

    $menu = Menu::where('household_id', $target->id)->sole();
    expect($menu->week_start->toDateString())->toBe(Week::current()->key())
        ->and($menu->status->value)->toBe('draft')
        ->and($menu->dishes()->count())->toBe(8);

    // The one-off dish is linked to the right ingredients.
    $sandwich = $menu->dishes()->where('name', 'Sandwich Mixto')->sole();
    expect($sandwich->ingredients()->with('ingredient')->orderBy('position')->get()
        ->map(fn ($line) => $line->ingredient->name.': '.$line->toQuantity()->format())->all())
        ->toBe(['Pan de molde: 4 rebanadas', 'Jamón de York: 4 lonchas', 'Queso: 4 lonchas']);
});

test('the dry run changes nothing', function () {
    $target = householdWithHistory()->currentHousehold();
    $before = householdFootprint($target);

    runManualSeeder($target, dryRun: true);

    expect(householdFootprint($target))->toBe($before);
});

test('the seeder refuses to run outside the local environment', function () {
    $target = householdWithHistory()->currentHousehold();
    putenv('SEED_HOUSEHOLD_ID='.$target->id);

    try {
        $this->seed(ManualTestDataSeeder::class);
    } finally {
        putenv('SEED_HOUSEHOLD_ID');
    }
})->throws(RuntimeException::class, 'ManualTestDataSeeder only runs with APP_ENV=local.');

test('the seeder needs an existing household', function () {
    app()->detectEnvironment(fn () => 'local');
    putenv('SEED_HOUSEHOLD_ID=999999');

    try {
        $this->seed(ManualTestDataSeeder::class);
    } finally {
        putenv('SEED_HOUSEHOLD_ID');
        app()->detectEnvironment(fn () => 'testing');
    }
})->throws(RuntimeException::class);

test('the seeder stops without deleting anything if another household uses its ingredients', function () {
    $target = householdWithHistory()->currentHousehold();
    $other = User::factory()->withHousehold()->create()->currentHousehold();
    $before = householdFootprint($target);

    // Corrupt row that the app never creates: stock of another household pointing to this ingredient.
    DB::table('inventory_items')->insert([
        'household_id' => $other->id, 'ingredient_id' => $target->ingredients()->value('id'),
        'location' => 'fridge', 'quantity' => '1', 'unit' => 'g', 'unit_group' => 'mass',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => runManualSeeder($target))->toThrow(RuntimeException::class);
    expect(householdFootprint($target))->toBe($before);
});
