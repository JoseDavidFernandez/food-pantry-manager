<?php

use App\Actions\Menus\PeriodRequirements;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\PlannedDish;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * RN-36 (purchase period), RN-37 («No comido») and the separation between physical stock,
 * committed ingredients and what to buy (RN-10).
 */
beforeEach(function () {
    // Wednesday 21 Oct 2026, 12:00 in Madrid.
    $this->travelTo('2026-10-21 10:00:00');
    $this->user = User::factory()->withHousehold()->create();
    $this->household = $this->user->currentHousehold();
    $this->actingAs($this->user);

    $this->huevos = Ingredient::factory()->for($this->household)->named('Huevos')->create();
    $this->stock = InventoryItem::factory()->for($this->huevos)->of('6', Unit::Piece, StorageLocation::Fridge)->create();
    $this->tortilla = Recipe::factory()->for($this->household)
        ->withIngredients([[$this->huevos, Quantity::of('4', Unit::Piece)]])
        ->create(['name' => 'Tortilla', 'base_servings' => 2]);
});

function plan(string $date, string $meal, Recipe $recipe, int $servings = 2): void
{
    $day = (int) CarbonImmutable::parse($date)->isoWeekday();
    $week = CarbonImmutable::parse($date)->startOfWeek()->format('Y-m-d');

    planInSlot(['week' => $week, 'day' => $day, 'meal' => $meal], [
        'type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => $servings,
    ])->assertSessionHasNoErrors();
}

/**
 * The period calculation (RN-36) as the list uses it, keyed by ingredient, with the same
 * period resolution as the page: the next 7 days by default, quick days or custom dates.
 *
 * @return array<string, array<string, mixed>>
 */
function periodLines(array $query = []): array
{
    $today = PeriodRequirements::today();
    $start = isset($query['start']) ? CarbonImmutable::parse($query['start'], 'Europe/Madrid') : $today;
    $end = isset($query['end']) ? CarbonImmutable::parse($query['end'], 'Europe/Madrid') : $today->addDays(($query['days'] ?? 7) - 1);

    // The page also opens fine for this period.
    test()->get(route('shopping-list.period', $query))->assertOk();

    return collect(app(PeriodRequirements::class)->calculate(auth()->user()->currentHousehold(), $start, $end))
        ->mapWithKeys(fn (array $line) => [$line['ingredient']->name => [
            'to_buy' => $line['to_buy']?->format(),
            'need' => $line['need']->format(),
            'have' => $line['have']?->format(),
            'committed' => $line['committed']->format(),
            'available' => $line['available']?->format(),
            'review' => $line['review'],
        ]])
        ->all();
}

function eggsInStock(): string
{
    return InventoryItem::query()->whereKey(test()->stock->id)->value('quantity');
}

test('6 eggs at home and 8 committed: buy 2, the physical stock stays at 6', function () {
    plan('2026-10-21', 'dinner', $this->tortilla);
    plan('2026-10-22', 'lunch', $this->tortilla);

    expect(periodLines()['Huevos'])->toMatchArray([
        'to_buy' => '2 unidades', 'need' => '8 unidades', 'have' => '6 unidades',
        'committed' => '8 unidades', 'available' => null,
    ])->and(eggsInStock())->toBe('6.000');
});

test('a new recipe increases the needs without touching the stock; changing it releases the old ingredients', function () {
    plan('2026-10-22', 'lunch', $this->tortilla);
    expect(periodLines()['Huevos'])->toMatchArray(['to_buy' => null, 'committed' => '4 unidades', 'available' => '2 unidades']);

    plan('2026-10-23', 'dinner', $this->tortilla, servings: 4);
    expect(periodLines()['Huevos'])->toMatchArray(['to_buy' => '6 unidades', 'committed' => '12 unidades']);

    // Friday dinner becomes a dish without eggs: its 8 eggs are released, the new one counts.
    $arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    $paella = Recipe::factory()->for($this->household)->withIngredients([[$arroz, Quantity::of('300', Unit::Gram)]])->create(['name' => 'Paella', 'base_servings' => 2]);
    plan('2026-10-23', 'dinner', $paella);

    expect(periodLines()['Huevos'])->toMatchArray(['to_buy' => null, 'committed' => '4 unidades'])
        ->and(periodLines()['Arroz']['to_buy'])->toBe('300 g')
        ->and(eggsInStock())->toBe('6.000');
});

test('moving a dish to another date or meal does not duplicate its needs', function () {
    plan('2026-10-22', 'lunch', $this->tortilla);
    plan('2026-10-23', 'lunch', $this->tortilla);

    $this->patch(route('menu.dishes.move', dishIn(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'])), ['to_day' => 6, 'to_meal' => 'dinner']);
    $this->patch(route('menu.dishes.move', dishIn(['week' => '2026-10-19', 'day' => 5, 'meal' => 'lunch'])), ['to_day' => 6, 'to_meal' => 'dinner']); // swap

    expect(periodLines()['Huevos'])->toMatchArray(['committed' => '8 unidades', 'to_buy' => '2 unidades']);
});

test('RN-37: «No comido» releases the ingredients without touching the stock; repeating it changes nothing; pending again counts once', function () {
    plan('2026-10-21', 'lunch', $this->tortilla);
    plan('2026-10-22', 'lunch', $this->tortilla);
    $slot = ['week' => '2026-10-19', 'day' => 3, 'meal' => 'lunch'];

    $this->patch(route('menu.dishes.eaten', dishIn($slot)), ['eaten' => '0'])->assertInertiaFlash('toast.type', 'success');
    $this->patch(route('menu.dishes.eaten', dishIn($slot)), ['eaten' => '0']);
    expect(periodLines()['Huevos'])->toMatchArray(['committed' => '4 unidades', 'to_buy' => null])
        ->and(eggsInStock())->toBe('6.000');

    // Still in the calendar, flagged.
    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page
        ->where('slots.3-lunch.0.name', 'Tortilla')
        ->where('slots.3-lunch.0.not_eaten', true)
        ->where('requirements.lines.0.need', '4 unidades'));

    $this->patch(route('menu.dishes.eaten', dishIn($slot)), ['eaten' => '1']);
    $this->patch(route('menu.dishes.eaten', dishIn($slot)), ['eaten' => '1']);
    expect(periodLines()['Huevos'])->toMatchArray(['committed' => '8 unidades', 'to_buy' => '2 unidades']);
});

test('RN-37: changing the dish of a «No comido» slot makes it pending again', function () {
    plan('2026-10-22', 'lunch', $this->tortilla);
    $this->patch(route('menu.dishes.eaten', dishIn(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'])), ['eaten' => '0']);
    expect(periodLines())->toBe([]);

    plan('2026-10-22', 'lunch', $this->tortilla, servings: 3);
    expect(PlannedDish::sole()->not_eaten_at)->toBeNull()
        ->and(periodLines()['Huevos']['committed'])->toBe('6 unidades');
});

test('past dates stop counting but stay in the menu as history', function () {
    plan('2026-10-19', 'lunch', $this->tortilla, servings: 10); // Monday, already past
    plan('2026-10-21', 'dinner', $this->tortilla);              // today still counts

    expect(periodLines()['Huevos'])->toMatchArray(['committed' => '4 unidades', 'to_buy' => null])
        ->and(PlannedDish::count())->toBe(2);
    $this->get(route('menu.show', '2026-10-19'))->assertInertia(fn (Assert $page) => $page->where('slots.1-lunch.0.name', 'Tortilla'));
});

test('periods of 7, 14 and 30 days and custom dates select the dishes of those dates', function () {
    plan('2026-10-24', 'lunch', $this->tortilla);  // +3
    plan('2026-10-31', 'lunch', $this->tortilla);  // +10
    plan('2026-11-10', 'lunch', $this->tortilla);  // +20

    expect(periodLines()['Huevos']['need'])->toBe('4 unidades')
        ->and(periodLines(['days' => 14])['Huevos']['need'])->toBe('8 unidades')
        ->and(periodLines(['days' => 30])['Huevos']['need'])->toBe('12 unidades');

    // Custom period with only the second dish: the first one (before it) uses 4 of the 6 eggs.
    expect(periodLines(['start' => '2026-10-28', 'end' => '2026-11-03'])['Huevos'])
        ->toMatchArray(['need' => '4 unidades', 'to_buy' => '2 unidades']);

    $this->get(route('shopping-list.period', ['start' => '2026-11-03', 'end' => '2026-10-28']))->assertSessionHasErrors('end');
    $this->get(route('shopping-list.period', ['days' => 9]))->assertSessionHasErrors('days');
});

test('the list follows the stock with no regeneration; compatible units are grouped and incompatible ones are not subtracted', function () {
    plan('2026-10-22', 'lunch', $this->tortilla, servings: 4);
    expect(periodLines()['Huevos']['to_buy'])->toBe('2 unidades');

    $this->put(route('inventory.update', $this->stock), ['quantity' => '10', 'unit' => 'piece', 'location' => 'fridge']);
    expect(periodLines()['Huevos']['to_buy'])->toBeNull();

    $arroz = Ingredient::factory()->for($this->household)->named('Arroz')->create();
    InventoryItem::factory()->for($arroz)->of('0.5', Unit::Kilogram, StorageLocation::Pantry)->create();
    $tomate = Ingredient::factory()->for($this->household)->named('Tomate frito')->create();
    InventoryItem::factory()->for($tomate)->of('1', Unit::Jar, StorageLocation::Pantry)->create();
    $risotto = Recipe::factory()->for($this->household)->withIngredients([
        [$arroz, Quantity::of('600', Unit::Gram)],
        [$tomate, Quantity::of('200', Unit::Gram)],
    ])->create(['name' => 'Risotto', 'base_servings' => 2]);
    $pilaf = Recipe::factory()->for($this->household)->withIngredients([[$arroz, Quantity::of('0.3', Unit::Kilogram)]])->create(['name' => 'Pilaf', 'base_servings' => 2]);
    plan('2026-10-23', 'lunch', $risotto);
    plan('2026-10-24', 'lunch', $pilaf);

    $lines = periodLines();
    expect($lines['Arroz'])->toMatchArray(['need' => '900 g', 'to_buy' => '400 g', 'review' => false])
        ->and($lines['Tomate frito'])->toMatchArray(['to_buy' => '200 g', 'have' => null, 'review' => true])
        ->and(collect($lines)->keys()->all())->toBe(['Arroz', 'Huevos', 'Tomate frito']);
});

test('another household: its dishes and stock never count, and it cannot mark ours as «No comido»', function () {
    plan('2026-10-22', 'lunch', $this->tortilla, servings: 4);

    $other = User::factory()->withHousehold()->create();
    $this->actingAs($other);
    expect(periodLines())->toBe([]);
    $this->patch(route('menu.dishes.eaten', dishIn(['week' => '2026-10-19', 'day' => 4, 'meal' => 'lunch'])), ['eaten' => '0']);

    $this->actingAs($this->user);
    expect(PlannedDish::sole()->not_eaten_at)->toBeNull()
        ->and(periodLines()['Huevos']['to_buy'])->toBe('2 unidades');
});
