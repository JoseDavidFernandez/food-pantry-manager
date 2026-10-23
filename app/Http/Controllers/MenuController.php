<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\IngredientCatalog;
use App\Actions\Menus\DishPlan;
use App\Actions\Menus\MenuPlanner;
use App\Actions\Menus\MenuRequirements;
use App\Actions\Menus\PeriodRequirements;
use App\Actions\Recipes\RecipeExpansion;
use App\Enums\DishType;
use App\Enums\Meal;
use App\Enums\Unit;
use App\Http\Requests\Menus\AssignDishRequest;
use App\Http\Requests\Menus\MoveDishRequest;
use App\Models\Menu;
use App\Models\PlannedDish;
use App\Models\PlannedDishIngredient;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * @phpstan-import-type Line from RecipeExpansion
 * @phpstan-import-type Choice from RecipeExpansion
 */
class MenuController extends Controller
{
    public function __construct(private readonly MenuPlanner $planner) {}

    public function show(Request $request, MenuRequirements $requirements, DishPlan $dishPlan, IngredientCatalog $catalog, ?string $week = null): Response
    {
        $household = $this->user($request)->currentHousehold();
        $week = $week === null ? Week::current() : $this->week($week);
        $menu = $this->planner->find($household, $week);

        // Each lunch and dinner holds its dishes in order (RN-16).
        $slots = [];
        $planned = 0;
        // Proposals for alternatives come from the stock still unassigned, dish after dish from
        // today on (RN-42); the lists use the same ones.
        $plan = $dishPlan->until($household, CarbonImmutable::parse($week->key(), Week::timezone())->addDays(6));

        foreach ($menu?->dishes()->with(['menu', 'recipe', 'ingredients.ingredient', 'choices'])->orderBy('day')->orderBy('position')->orderBy('id')->get() ?? [] as $dish) {
            $lines = $plan['by_dish'][$dish->id] ?? $dishPlan->lines($dish, units: $plan['units']);
            $slots["{$dish->day}-{$dish->meal->value}"][] = $this->dishPayload($dish, $this->alternatives($lines));
            $planned++;
        }

        // Days already past stay as history and do not generate purchases (RN-36).
        $today = PeriodRequirements::today()->format('Y-m-d');
        $days = array_map(fn (array $day) => [...$day, 'is_past' => $day['date'] < $today], $week->days());

        return Inertia::render('menu/Week', [
            'week' => [
                'key' => $week->key(),
                'label' => $week->label(),
                'previous' => $week->previous()->key(),
                'next' => $week->next()->key(),
                'current' => Week::current()->key(),
                'is_current' => $week->isCurrent(),
            ],
            'days' => $days,
            'meals' => array_map(fn (Meal $meal) => ['value' => $meal->value, 'label' => $meal->label()], Meal::cases()),
            'slots' => (object) $slots,
            'planned' => $planned,
            'filledSlots' => count($slots),
            'slotsPerWeek' => Menu::SLOTS_PER_WEEK,
            'recipes' => $household->recipes()->active()->withCount(['ingredients', 'components'])->orderBy('name')->get()
                ->map(fn (Recipe $recipe) => [
                    'id' => $recipe->id,
                    'name' => $recipe->name,
                    'base_servings' => $recipe->base_servings,
                    'complete' => $recipe->ingredients_count > 0 || $recipe->components_count > 0,
                ]),
            'units' => Unit::options(),
            'suggestions' => $catalog->suggestions($household),
            'requirements' => $requirements->forMenu($household, $week, $menu),
        ]);
    }

    /**
     * Add a dish at the end of a lunch or dinner (RN-16).
     */
    public function store(AssignDishRequest $request, string $week, int $day, string $meal): RedirectResponse
    {
        $household = $this->user($request)->currentHousehold();
        $week = $this->week($week);
        $menu = $this->planner->findOrCreate($household, $week);

        $dish = $this->planner->add(
            $menu,
            $day,
            $this->meal($meal),
            $request->dishType(),
            $request->recipe(),
            $request->filled('name') ? trim($request->string('name')->toString()) : null,
            $request->integer('servings'),
            $request->ingredientRows(),
        );

        $this->saved($dish, $week);

        return back();
    }

    public function update(AssignDishRequest $request, int $dish): RedirectResponse
    {
        $planned = $this->dish($request, $dish);

        $this->planner->update(
            $planned,
            $request->dishType(),
            $request->recipe(),
            $request->filled('name') ? trim($request->string('name')->toString()) : null,
            $request->integer('servings'),
            $request->ingredientRows(),
        );

        $this->saved($planned, Week::containing($planned->menu->week_start->format('Y-m-d')));

        return back();
    }

    public function destroy(Request $request, int $dish): RedirectResponse
    {
        $planned = $this->dish($request, $dish);
        $this->planner->remove($planned);

        Inertia::flash('toast', ['type' => 'success', 'message' => "«{$planned->displayName()}» retirado del menú."]);

        return back();
    }

    public function move(MoveDishRequest $request, int $dish): RedirectResponse
    {
        $planned = $this->planner->move($this->dish($request, $dish), $request->integer('to_day'), $request->targetMeal());

        Inertia::flash('toast', ['type' => 'success', 'message' => "«{$planned->displayName()}» movido."]);

        return back();
    }

    public function position(Request $request, int $dish): RedirectResponse
    {
        $validated = $request->validate(['direction' => ['required', 'in:up,down']]);
        $this->planner->reorder($this->dish($request, $dish), $validated['direction'] === 'up' ? -1 : 1);

        return back();
    }

    /**
     * RN-42: the option of a group of alternative ingredients for this dish, confirmed or changed
     * by the user. It is kept for the dish, so every period uses it.
     */
    public function choice(Request $request, DishPlan $dishPlan, int $dish): RedirectResponse
    {
        $planned = $this->dish($request, $dish);
        $validated = $request->validate([
            'recipe_id' => ['required', 'integer'],
            'group' => ['required', 'integer'],
            'ingredient_id' => ['required', 'integer'],
        ]);

        $group = collect($this->alternatives($dishPlan->lines($planned)))
            ->first(fn (array $choice) => $choice['recipe_id'] === (int) $validated['recipe_id'] && $choice['group'] === (int) $validated['group']);
        $option = collect($group['options'] ?? [])->firstWhere('ingredient_id', (int) $validated['ingredient_id']);

        if ($group === null || $option === null) {
            throw ValidationException::withMessages(['ingredient_id' => 'Esa opción ya no está en la receta. Vuelve a abrir el menú.']);
        }

        $this->planner->choose($planned, $group['recipe_id'], $group['group'], $option['ingredient_id']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "«{$planned->displayName()}» se preparará con {$option['name']}."]);

        return back();
    }

    /**
     * Groups of alternative ingredients of a dish, with the option in use (RN-42).
     *
     * @param  list<Line>  $lines
     * @return list<Choice>
     */
    private function alternatives(array $lines): array
    {
        return array_values(array_filter(array_map(fn (array $line) => $line['choice'], $lines)));
    }

    /**
     * RN-37: mark a planned dish as «No comido» (eaten = 0) or as pending again (eaten = 1).
     */
    public function eaten(Request $request, int $dish): RedirectResponse
    {
        $validated = $request->validate(['eaten' => ['required', 'boolean']]);
        $planned = $this->planner->setNotEaten($this->dish($request, $dish), ! $validated['eaten']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $planned->isNotEaten()
            ? "«{$planned->displayName()}» marcado como no comido: sus ingredientes ya no cuentan."
            : "«{$planned->displayName()}» vuelve a estar pendiente."]);

        return back();
    }

    /**
     * A planned dish of the user's household only (404 otherwise, RN-02).
     */
    private function dish(Request $request, int $id): PlannedDish
    {
        $household = $this->user($request)->currentHousehold();

        return PlannedDish::query()
            ->whereKey($id)
            ->whereHas('menu', fn ($query) => $query->where('household_id', $household->id))
            ->with('menu')
            ->firstOrFail();
    }

    /**
     * The toast after saving a dish: a day already past is kept, but warned about (RN-36).
     */
    private function saved(PlannedDish $dish, Week $week): void
    {
        $date = $week->start->addDays($dish->day - 1)->format('Y-m-d');

        Inertia::flash('toast', $date < PeriodRequirements::today()->format('Y-m-d')
            ? ['type' => 'warning', 'message' => "«{$dish->displayName()}» guardado en un día que ya ha pasado: se conserva como historial, pero no cuenta para la lista de la compra."]
            : ['type' => 'success', 'message' => "«{$dish->displayName()}» guardado en el menú."]);
    }

    /**
     * @param  list<array<string, mixed>>  $alternatives
     * @return array<string, mixed>
     */
    private function dishPayload(PlannedDish $dish, array $alternatives = []): array
    {
        $ingredients = $dish->ingredients->map(fn (PlannedDishIngredient $line) => [
            'ingredient' => $line->ingredient->name,
            'quantity' => $line->toQuantity()->formattedAmount(),
            'unit' => $line->unit->value,
            'display' => $line->ingredient->name.': '.$line->toQuantity()->format(),
        ])->values();

        $isRecipe = $dish->type === DishType::Recipe;

        return [
            'id' => $dish->id,
            'position' => $dish->position,
            'type' => $dish->type->value,
            'name' => $dish->displayName(),
            'recipe_id' => $dish->recipe_id,
            'recipe_archived' => $isRecipe && $dish->recipe?->isArchived() === true,
            'servings' => $dish->servings,
            'not_eaten' => $dish->isNotEaten(),
            'ingredients' => $ingredients,
            'complete' => $isRecipe
                ? $dish->recipe?->ingredients()->exists() === true || $dish->recipe?->components()->exists() === true
                : $ingredients->isNotEmpty(),
            'alternatives' => $alternatives,
        ];
    }

    private function week(string $value): Week
    {
        try {
            return Week::containing($value);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    private function meal(string $value): Meal
    {
        return Meal::tryFrom($value) ?? abort(404);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
