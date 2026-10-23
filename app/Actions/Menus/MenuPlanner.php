<?php

namespace App\Actions\Menus;

use App\Actions\Ingredients\IngredientCatalog;
use App\Enums\DishType;
use App\Enums\Meal;
use App\Enums\MenuStatus;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\PlannedDish;
use App\Models\PlannedDishChoice;
use App\Models\Recipe;
use App\Support\Quantity;
use App\Support\Week;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Weekly menu planning (RN-15 to RN-17): each lunch and dinner holds several ordered dishes.
 * Planning never reserves or deducts stock (RN-10).
 */
class MenuPlanner
{
    public function __construct(private readonly IngredientCatalog $catalog) {}

    public function find(Household $household, Week $week): ?Menu
    {
        return $household->menus()->whereDate('week_start', $week->key())->first();
    }

    public function findOrCreate(Household $household, Week $week): Menu
    {
        return $this->find($household, $week)
            ?? $household->menus()->create(['week_start' => $week->key(), 'status' => MenuStatus::Draft]);
    }

    /**
     * Add a dish at the end of a lunch or dinner, which can hold several dishes in order
     * (RN-16, 2026-10-22). A string ingredient is a new one the user explicitly asked to create;
     * it is created here, in the same transaction as the dish (RN-03).
     *
     * @param  list<array{ingredient: Ingredient|string, quantity: Quantity, note: string|null}>  $rows
     */
    public function add(Menu $menu, int $day, Meal $meal, DishType $type, ?Recipe $recipe, ?string $name, int $servings, array $rows = []): PlannedDish
    {
        return DB::transaction(function () use ($menu, $day, $meal, $type, $recipe, $name, $servings, $rows) {
            $this->lock($menu);

            /** @var PlannedDish $dish */
            $dish = $menu->dishes()->create([
                'day' => $day,
                'meal' => $meal,
                'position' => $this->nextPosition($menu, $day, $meal),
                ...$this->content($type, $recipe, $name, $servings),
            ]);
            $this->replaceIngredients($menu, $dish, $type, $rows);

            return $dish;
        });
    }

    /**
     * Change what a planned dish is: another recipe, servings or one-off ingredients. It keeps
     * its place; a dish marked «No comido» counts again (RN-37).
     *
     * @param  list<array{ingredient: Ingredient|string, quantity: Quantity, note: string|null}>  $rows
     */
    public function update(PlannedDish $dish, DishType $type, ?Recipe $recipe, ?string $name, int $servings, array $rows = []): PlannedDish
    {
        return DB::transaction(function () use ($dish, $type, $recipe, $name, $servings, $rows) {
            $this->lock($dish->menu);

            $dish->update($this->content($type, $recipe, $name, $servings));
            $this->replaceIngredients($dish->menu, $dish, $type, $rows);

            return $dish;
        });
    }

    public function remove(PlannedDish $dish): void
    {
        DB::transaction(function () use ($dish) {
            $this->lock($dish->menu);
            $dish->delete();
            $this->renumber($dish->menu, $dish->day, $dish->meal);
        });
    }

    /**
     * The option chosen for a group of alternative ingredients of the dish (RN-42).
     */
    public function choose(PlannedDish $dish, int $recipeId, int $group, int $ingredientId): void
    {
        PlannedDishChoice::query()->updateOrCreate(
            ['planned_dish_id' => $dish->id, 'recipe_id' => $recipeId, 'alternative_group' => $group],
            ['ingredient_id' => $ingredientId],
        );
    }

    /**
     * «No comido» (RN-37): the dish stays in the menu and its ingredients stop counting, or count
     * again when it is pending. It sets a state instead of toggling it, so repeating the request
     * never releases or commits anything twice. Stock is never touched (RN-10).
     */
    public function setNotEaten(PlannedDish $dish, bool $notEaten): PlannedDish
    {
        return DB::transaction(function () use ($dish, $notEaten) {
            $this->lock($dish->menu);

            if ($dish->isNotEaten() !== $notEaten) {
                $dish->forceFill(['not_eaten_at' => $notEaten ? now() : null])->save();
            }

            return $dish;
        });
    }

    /**
     * Move a dish to the end of another lunch or dinner of the same week. Nothing is replaced:
     * a slot holds several dishes. Ingredients of one-off dishes travel with their dish.
     */
    public function move(PlannedDish $dish, int $toDay, Meal $toMeal): PlannedDish
    {
        return DB::transaction(function () use ($dish, $toDay, $toMeal) {
            $menu = $dish->menu;
            $this->lock($menu);
            [$fromDay, $fromMeal] = [$dish->day, $dish->meal];

            if ($fromDay === $toDay && $fromMeal === $toMeal) {
                return $dish;
            }

            $dish->update(['day' => $toDay, 'meal' => $toMeal, 'position' => $this->nextPosition($menu, $toDay, $toMeal)]);
            $this->renumber($menu, $fromDay, $fromMeal);

            return $dish;
        });
    }

    /**
     * Move a dish one place up or down within its lunch or dinner.
     */
    public function reorder(PlannedDish $dish, int $offset): void
    {
        DB::transaction(function () use ($dish, $offset) {
            $menu = $dish->menu;
            $this->lock($menu);

            $ids = $this->slot($menu, $dish->day, $dish->meal)->pluck('id')->all();
            $from = array_search($dish->id, $ids, true);
            $to = is_int($from) ? $from + $offset : -1;

            if (! is_int($from) || $to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $position => $id) {
                PlannedDish::query()->whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /**
     * @return array{type: DishType, recipe_id: int|null, name: string|null, servings: int, not_eaten_at: null}
     */
    private function content(DishType $type, ?Recipe $recipe, ?string $name, int $servings): array
    {
        return [
            'type' => $type,
            'recipe_id' => $type === DishType::Recipe ? $recipe?->id : null,
            'name' => $type === DishType::Adhoc ? $name : null,
            'servings' => $servings,
            // RN-37: a new or changed dish counts again.
            'not_eaten_at' => null,
        ];
    }

    /**
     * @param  list<array{ingredient: Ingredient|string, quantity: Quantity, note: string|null}>  $rows
     */
    private function replaceIngredients(Menu $menu, PlannedDish $dish, DishType $type, array $rows): void
    {
        $dish->ingredients()->delete();

        if ($type !== DishType::Adhoc) {
            return;
        }

        foreach ($rows as $position => $row) {
            $ingredient = $row['ingredient'] instanceof Ingredient
                ? $row['ingredient']
                : $this->catalog->findOrCreate($menu->household, $row['ingredient']);

            $line = $dish->ingredients()->make([
                'ingredient_id' => $ingredient->id,
                'position' => $position,
            ]);
            $line->setQuantity($row['quantity']);
            $line->save();
        }
    }

    /**
     * @return Collection<int, PlannedDish>
     */
    private function slot(Menu $menu, int $day, Meal $meal): Collection
    {
        return $menu->dishes()->where('day', $day)->where('meal', $meal)->orderBy('position')->orderBy('id')->get();
    }

    private function nextPosition(Menu $menu, int $day, Meal $meal): int
    {
        $last = $menu->dishes()->where('day', $day)->where('meal', $meal)->max('position');

        return $last === null ? 0 : (int) $last + 1;
    }

    /**
     * Positions 0, 1, 2… after a dish leaves its slot.
     */
    private function renumber(Menu $menu, int $day, Meal $meal): void
    {
        foreach ($this->slot($menu, $day, $meal) as $position => $dish) {
            if ($dish->position !== $position) {
                PlannedDish::query()->whereKey($dish->id)->update(['position' => $position]);
            }
        }
    }

    /**
     * Serialize the changes of one menu (two tabs editing the same week). Menus are no longer
     * closed (RN-36 replaced closing on 2026-10-21): every week stays editable.
     */
    private function lock(Menu $menu): void
    {
        Menu::query()->whereKey($menu->id)->lockForUpdate()->firstOrFail();
    }
}
