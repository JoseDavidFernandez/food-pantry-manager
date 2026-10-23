<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\IngredientCatalog;
use App\Actions\Recipes\RecipeBook;
use App\Actions\Recipes\RecipeCycle;
use App\Actions\Recipes\RecipeExpansion;
use App\Enums\Unit;
use App\Http\Requests\Recipes\RecipeRequest;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecipeController extends Controller
{
    public function __construct(
        private readonly RecipeBook $book,
        private readonly IngredientCatalog $catalog,
        private readonly RecipeExpansion $expansion,
    ) {}

    public function index(Request $request): Response
    {
        $household = $this->user($request)->currentHousehold();
        $showArchived = $request->boolean('archived');

        $recipes = $household->recipes()
            ->when($showArchived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->withCount(['ingredients', 'plannedDishes'])
            ->orderBy('name')
            ->get()
            ->map(fn (Recipe $recipe) => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'base_servings' => $recipe->base_servings,
                'ingredient_count' => $recipe->ingredients_count,
                'archived' => $recipe->isArchived(),
            ]);

        return Inertia::render('recipes/Index', [
            'recipes' => $recipes,
            'showArchived' => $showArchived,
            'archivedCount' => $household->recipes()->whereNotNull('archived_at')->count(),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if (Gate::denies('household.write')) {
            return $this->readOnly();
        }

        return Inertia::render('recipes/Form', [
            'recipe' => null,
            'units' => Unit::options(),
            'suggestions' => $this->catalog->suggestions($this->user($request)->currentHousehold()),
            'componentOptions' => $this->componentOptions($request, null),
        ]);
    }

    public function store(RecipeRequest $request): RedirectResponse
    {
        $recipe = $this->saving(fn () => $this->book->save($this->user($request)->currentHousehold(), null, $request->recipeAttributes(), $request->ingredientRows(), $request->components()));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Receta «{$recipe->name}» guardada."]);

        return to_route('recipes.show', $recipe);
    }

    public function show(Request $request, Recipe $recipe): Response
    {
        Gate::authorize('view', $recipe);

        // RN-13: scaled preview for another number of servings (visual only; nothing is stored).
        $servings = max(1, min(100, $request->integer('servings', $recipe->base_servings)));

        $ingredients = $recipe->ingredients()->with('ingredient')->get()
            ->map(fn (RecipeIngredient $line) => [
                'ingredient' => $line->ingredient->name,
                'quantity' => $line->toQuantity()->scaled($servings, $recipe->base_servings)->format(),
                'note' => $line->note,
                // RN-42: lines of the same group are «one of them».
                'alternative_group' => $line->alternative_group,
            ]);

        // RN-43: preparations with the servings used for these servings, and everything it needs.
        $components = $recipe->components()->with('component')->get()
            ->map(fn (RecipeComponent $component) => [
                'id' => $component->component_recipe_id,
                'name' => $component->component->name,
                'servings' => str_replace('.', ',', rtrim(rtrim(bcdiv((string) ($component->servings * $servings), (string) $recipe->base_servings, 2), '0'), '.')),
                'archived' => $component->component->isArchived(),
            ]);

        return Inertia::render('recipes/Show', [
            'recipe' => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'base_servings' => $recipe->base_servings,
                'instructions' => $recipe->instructions,
                'archived' => $recipe->isArchived(),
                'referenced' => $this->book->isReferenced($recipe),
            ],
            'servings' => $servings,
            'ingredients' => $ingredients,
            'components' => $components,
            'totals' => $components->isEmpty() ? [] : $this->totals($recipe, $servings),
            'usedIn' => $recipe->usedIn()->with('recipe')->get()->map(fn (RecipeComponent $use) => [
                'id' => $use->recipe_id,
                'name' => $use->recipe->name,
            ]),
        ]);
    }

    public function edit(Request $request, Recipe $recipe): Response|RedirectResponse
    {
        Gate::authorize('update', $recipe);

        if (Gate::denies('household.write')) {
            return $this->readOnly();
        }

        $lines = $recipe->ingredients()->with('ingredient')->get()->values();

        return Inertia::render('recipes/Form', [
            'recipe' => [
                'id' => $recipe->id,
                'name' => $recipe->name,
                'base_servings' => $recipe->base_servings,
                'instructions' => $recipe->instructions,
                'ingredients' => $lines->map(fn (RecipeIngredient $line, int $index) => [
                    'ingredient' => $line->ingredient->name,
                    'quantity' => $line->toQuantity()->formattedAmount(),
                    'unit' => $line->unit->value,
                    'note' => $line->note,
                    // RN-42: «or» of the row before it.
                    'alternative' => $line->alternative_group !== null && $index > 0 && $lines[$index - 1]->alternative_group === $line->alternative_group,
                ]),
                'components' => $recipe->components()->get()->map(fn (RecipeComponent $component) => [
                    'recipe_id' => $component->component_recipe_id,
                    'servings' => $component->servings,
                ]),
            ],
            'units' => Unit::options(),
            'suggestions' => $this->catalog->suggestions($this->user($request)->currentHousehold()),
            'componentOptions' => $this->componentOptions($request, $recipe),
        ]);
    }

    /**
     * Recipes that can be included as a preparation (RN-43): active ones (and those already
     * included) except this recipe and any that includes it.
     *
     * @return array<int, array{id: int, name: string, base_servings: int, archived: bool}>
     */
    private function componentOptions(Request $request, ?Recipe $recipe): array
    {
        $included = $recipe?->components()->pluck('component_recipe_id')->all() ?? [];

        return $this->user($request)->currentHousehold()->recipes()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereIn('id', $included))
            ->orderBy('name')
            ->get()
            ->reject(fn (Recipe $option) => $recipe !== null && $this->book->includes($option, $recipe))
            ->map(fn (Recipe $option) => [
                'id' => $option->id,
                'name' => $option->name,
                'base_servings' => $option->base_servings,
                'archived' => $option->isArchived(),
            ])
            ->values()
            ->all();
    }

    /**
     * Everything the recipe needs with its preparations, combined (RN-43). A group of
     * alternatives lists its options (RN-42): the choice is made per planned dish.
     *
     * @return list<array{ingredient: string, quantity: string}>
     */
    private function totals(Recipe $recipe, int $servings): array
    {
        $units = FoodUnitsMap::forHousehold($recipe->household);
        /** @var array<string, array{ingredient: string, quantity: Quantity|string}> $totals */
        $totals = [];

        foreach ($this->expansion->forServings($recipe, $servings, $units) as $line) {
            $choice = $line['choice'];

            if ($choice !== null) {
                $totals['choice:'.$choice['recipe_id'].':'.$choice['group']] = [
                    'ingredient' => implode(' o ', array_column($choice['options'], 'name')),
                    'quantity' => implode(' o ', array_column($choice['options'], 'quantity')),
                ];

                continue;
            }

            [$key, $quantity] = $units->keyed($line['ingredient']->id, $line['quantity']);
            $previous = $totals[$key]['quantity'] ?? null;
            $totals[$key] = [
                'ingredient' => $line['ingredient']->name,
                'quantity' => $previous instanceof Quantity ? $previous->plus($quantity) : $quantity,
            ];
        }

        $result = array_map(fn (array $total) => [
            'ingredient' => $total['ingredient'],
            'quantity' => $total['quantity'] instanceof Quantity ? $total['quantity']->readable() : $total['quantity'],
        ], array_values($totals));

        usort($result, fn (array $a, array $b) => strcoll(mb_strtolower($a['ingredient']), mb_strtolower($b['ingredient'])));

        return $result;
    }

    public function update(RecipeRequest $request, Recipe $recipe): RedirectResponse
    {
        $revisions = $recipe->revisions()->count();
        $this->saving(fn () => $this->book->save($this->user($request)->currentHousehold(), $recipe, $request->recipeAttributes(), $request->ingredientRows(), $request->components()));

        // RN-45: dishes already past keep the recipe they were cooked with.
        Inertia::flash('toast', ['type' => 'success', 'message' => $recipe->revisions()->count() > $revisions
            ? 'Receta actualizada. Los platos que ya han pasado conservan la receta anterior en el inventario previsto; los próximos usan la nueva.'
            : 'Receta actualizada.']);

        return to_route('recipes.show', $recipe);
    }

    public function destroy(Recipe $recipe): RedirectResponse
    {
        Gate::authorize('delete', $recipe);

        // RN-14, RN-43: a recipe used in a menu or in another recipe cannot be deleted, only archived.
        if ($this->book->isReferenced($recipe)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "«{$recipe->name}» está en algún menú o es una preparación de otra receta: no se puede eliminar, pero puedes archivarla."]);

            return back();
        }

        $this->book->delete($recipe);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Receta «{$recipe->name}» eliminada."]);

        return to_route('recipes.index');
    }

    public function archive(Recipe $recipe): RedirectResponse
    {
        Gate::authorize('update', $recipe);

        $this->book->archive($recipe);

        Inertia::flash('toast', ['type' => 'success', 'message' => "«{$recipe->name}» archivada. Ya no se ofrecerá al planificar."]);

        return back();
    }

    public function restore(Recipe $recipe): RedirectResponse
    {
        Gate::authorize('update', $recipe);

        $this->book->restore($recipe);

        Inertia::flash('toast', ['type' => 'success', 'message' => "«{$recipe->name}» restaurada."]);

        return back();
    }

    /**
     * A cycle that appears between validating and saving (another tab) is a validation error.
     */
    private function saving(Closure $save): Recipe
    {
        try {
            return $save();
        } catch (RecipeCycle $cycle) {
            throw ValidationException::withMessages(['components' => $cycle->getMessage()]);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Forms are only for members who can change data (RN-38).
     */
    private function readOnly(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => 'Tu rol en el hogar es de lectura: no puedes crear ni editar recetas.']);

        return to_route('recipes.index');
    }
}
