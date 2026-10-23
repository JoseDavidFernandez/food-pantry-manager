<?php

namespace App\Http\Controllers;

use App\Actions\Menus\MenuPlanner;
use App\Actions\Receptions\ReceptionProgress;
use App\Actions\ShoppingLists\ShoppingListSync;
use App\Enums\DishType;
use App\Enums\Meal;
use App\Models\Menu;
use App\Models\PlannedDish;
use App\Models\User;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home (USER-FLOWS §0, RN-34): answers at a glance what is for lunch and dinner today, what is
 * planned for the week and what is left to buy, from the real menu and list. Read only.
 */
class DashboardController extends Controller
{
    /** Pending shopping lines shown on the home page; the full list is one click away. */
    private const LIST_PREVIEW = 6;

    public function __invoke(Request $request, MenuPlanner $planner, ShoppingListSync $sync, ReceptionProgress $progress): Response
    {
        /** @var User $user */
        $user = $request->user();
        $household = $user->currentHousehold();
        $week = Week::current();
        $today = CarbonImmutable::now(Week::timezone())->isoWeekday();
        $menu = $planner->find($household, $week);

        $dishes = [];

        // Each lunch and dinner holds its dishes in order (RN-16).
        foreach ($menu?->dishes()->with(['recipe' => fn ($query) => $query->withCount('ingredients'), 'ingredients'])->orderBy('position')->orderBy('id')->get() ?? [] as $dish) {
            $dishes[$dish->day][$dish->meal->value][] = $this->dishSummary($dish);
        }

        $days = array_map(fn (array $day) => [
            ...$day,
            'is_today' => $day['day'] === $today,
            'lunch' => $dishes[$day['day']][Meal::Lunch->value] ?? [],
            'dinner' => $dishes[$day['day']][Meal::Dinner->value] ?? [],
        ], $week->days());

        // The persistent list of the next 7 days (RN-36): what is still to buy, manual lines included.
        $list = $sync->forRolling($household, 7);
        $pending = [];
        $toReview = 0;

        foreach ($progress->forList($list) as $line) {
            $item = $line['item'];
            $toReview += $item->needs_review ? 1 : 0;

            if (! $item->is_purchased && $line['pending']) {
                $pending[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'quantity' => $line['remaining']?->readable(),
                    'needs_review' => $item->needs_review,
                ];
            }
        }

        return Inertia::render('Dashboard', [
            'week' => ['key' => $week->key(), 'label' => $week->label()],
            'today' => $days[$today - 1],
            'days' => $days,
            'menu' => $menu === null ? null : [
                'planned' => array_sum(array_map(fn (array $meals) => array_sum(array_map('count', $meals)), $dishes)),
                'slots' => Menu::SLOTS_PER_WEEK,
            ],
            'list' => [
                'to_buy' => count($pending),
                'to_review' => $toReview,
                'items' => array_slice($pending, 0, self::LIST_PREVIEW),
            ],
            'stockCount' => $household->inventoryItems()->count(),
            'recipeCount' => $household->recipes()->active()->count(),
        ]);
    }

    /**
     * @return array{name: string, servings: int, type: string, recipe_id: int|null, not_eaten: bool, complete: bool}
     */
    private function dishSummary(PlannedDish $dish): array
    {
        return [
            'name' => $dish->displayName(),
            'servings' => $dish->servings,
            'type' => $dish->type->value,
            'recipe_id' => $dish->recipe_id,
            'not_eaten' => $dish->isNotEaten(),
            'complete' => $dish->type === DishType::Recipe
                ? ($dish->recipe->ingredients_count ?? 0) > 0
                : $dish->ingredients->isNotEmpty(),
        ];
    }
}
