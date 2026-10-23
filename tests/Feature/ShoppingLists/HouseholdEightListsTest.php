<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Point 2 of the review of 2026-10-22: the two lists of household 8 that changed after the
 * snapshot. Opening Home or the list reconciles the quick 7-day list (only `regenerated_at`
 * changes), and «Lista de la compra de esta semana» on the menu of a week that started on
 * Monday creates the list of that Monday–Sunday. Nothing the user wrote is lost.
 */
test('opening Home and the list of this week reproduces both changes without losing anything', function () {
    // Thursday 22 Oct 2026, 11:16 in Madrid; the dishes are on Monday and Tuesday (past).
    $this->travelTo('2026-10-22 09:15:00');
    $user = User::factory()->withHousehold()->create();
    $household = $user->currentHousehold();
    $this->actingAs($user);
    $huevos = Ingredient::factory()->for($household)->named('Huevos')->create();
    $recipe = Recipe::factory()->for($household)->withIngredients([[$huevos, Quantity::of('4', Unit::Piece)]])->create(['base_servings' => 2]);

    foreach ([1, 2] as $day) {
        $this->post(route('menu.dishes.store', ['week' => '2026-10-19', 'day' => $day, 'meal' => 'lunch']), [
            'type' => 'recipe', 'recipe_id' => $recipe->id, 'servings' => 2,
        ]);
    }

    // The quick list exists (opened before) and has a line written by the user.
    $this->get(route('shopping-list.period'))->assertOk();
    $rolling = ShoppingList::sole();
    $this->post(route('shopping-lists.items.store', $rolling), ['item' => 'Papel de cocina', 'free_text' => '1']);
    $before = (array) DB::table('shopping_lists')->where('id', $rolling->id)->first(['period_start', 'period_end', 'rolling_days', 'generated_at']);

    // 1. Home a minute later: the 7-day list is reconciled; only the timestamp moves.
    $this->travelTo('2026-10-22 09:16:28');
    $this->get(route('dashboard'))->assertOk();
    expect((array) DB::table('shopping_lists')->where('id', $rolling->id)->first(['period_start', 'period_end', 'rolling_days', 'generated_at']))->toBe($before)
        ->and($rolling->fresh()->regenerated_at->format('H:i:s'))->toBe('09:16:28')
        ->and($rolling->items()->pluck('name')->all())->toBe(['Papel de cocina']);

    // 2. «Lista de la compra de esta semana» on the menu of the week of the 19th.
    $this->travelTo('2026-10-22 09:16:36');
    $this->get(route('shopping-list.period', ['start' => '2026-10-19', 'end' => '2026-10-25']))->assertOk();
    $week = ShoppingList::query()->whereKeyNot($rolling->id)->sole();

    expect($week->period_start->format('Y-m-d'))->toBe('2026-10-19')
        ->and($week->period_end->format('Y-m-d'))->toBe('2026-10-25')
        ->and($week->rolling_days)->toBeNull()
        // Past dishes generate nothing; the manual line is carried, the source keeps its own.
        ->and($week->items()->pluck('name')->all())->toBe(['Papel de cocina'])
        ->and($rolling->items()->pluck('name')->all())->toBe(['Papel de cocina']);
});
