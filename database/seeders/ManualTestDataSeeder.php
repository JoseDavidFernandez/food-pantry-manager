<?php

namespace Database\Seeders;

use App\Actions\Ingredients\IngredientCatalog;
use App\Actions\Inventory\StockManager;
use App\Actions\Menus\MenuPlanner;
use App\Actions\Recipes\RecipeBook;
use App\Enums\DishType;
use App\Enums\Meal;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\Ingredient;
use App\Support\Quantity;
use App\Support\Week;
use Database\Seeders\Concerns\CleansHouseholdData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Development only: replaces the functional data of ONE household with a coherent set for manual
 * tests of Phases 2 to 6 (catalog, stock, recipes, a one-off dish and a draft menu of the current
 * week). Users, households, memberships and other households are never touched. The menu is left
 * open: closing it, the shopping list and receptions are done by hand in the browser.
 *
 *   SEED_HOUSEHOLD_ID=1 SEED_DRY_RUN=1 php artisan db:seed --class=ManualTestDataSeeder
 *   SEED_HOUSEHOLD_ID=1 php artisan db:seed --class=ManualTestDataSeeder
 *
 * With SEED_DRY_RUN=1 everything runs inside a transaction that is rolled back at the end.
 */
class ManualTestDataSeeder extends Seeder
{
    use CleansHouseholdData;

    public function __construct(
        private readonly IngredientCatalog $catalog,
        private readonly StockManager $stock,
        private readonly RecipeBook $recipes,
        private readonly MenuPlanner $planner,
    ) {}

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('ManualTestDataSeeder only runs with APP_ENV=local.');
        }

        $householdId = (int) getenv('SEED_HOUSEHOLD_ID');
        $household = Household::query()->find($householdId)
            ?? throw new RuntimeException('Set SEED_HOUSEHOLD_ID to an existing household id.');
        $dryRun = getenv('SEED_DRY_RUN') === '1';

        DB::beginTransaction();

        try {
            Household::query()->whereKey($household->id)->lockForUpdate()->first();
            $this->ensureNothingOutsideReferencesTheHousehold($household);

            $this->report('Borrado (solo hogar '.$household->id.')', $this->clean($household));
            $this->report('Creado', $this->seed($household));

            if ($dryRun) {
                DB::rollBack();
                $this->command->warn('SEED_DRY_RUN: cambios deshechos, la base de datos no se ha modificado.');

                return;
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * @return array<string, int>
     */
    private function seed(Household $household): array
    {
        // Catalog with the usual unit of each ingredient. «Pan»/«Pan de molde» and «Queso»/«Queso rallado» stay separate.
        $catalog = [
            'Arroz' => Unit::Gram, 'Pasta' => Unit::Gram, 'Pechuga de pollo' => Unit::Gram, 'Huevos' => Unit::Piece,
            'Leche' => Unit::Litre, 'Tomate' => Unit::Gram, 'Tomate frito' => Unit::Jar, 'Cebolla' => Unit::Piece,
            'Patata' => Unit::Gram, 'Zanahoria' => Unit::Piece, 'Calabacín' => Unit::Piece, 'Pimiento' => Unit::Piece,
            'Ajo' => Unit::Piece, 'Queso rallado' => Unit::Gram, 'Queso' => Unit::DeliSlice, 'Yogur natural' => Unit::Piece,
            'Garbanzos cocidos' => Unit::Jar, 'Atún en conserva' => Unit::Can, 'Pan' => Unit::Piece,
            'Pan de molde' => Unit::Slice, 'Jamón de York' => Unit::DeliSlice, 'Aceite de oliva' => Unit::Millilitre,
            'Sal' => Unit::Gram,
        ];

        /** @var array<string, Ingredient> $ingredients */
        $ingredients = [];

        foreach ($catalog as $name => $unit) {
            $ingredients[$name] = $this->catalog->create($household, $name, $unit);
        }

        // Unambiguous aliases to test explicit recognition (RN-04).
        $this->catalog->addAlias($ingredients['Patata'], 'Patatas');
        $this->catalog->addAlias($ingredients['Huevos'], 'Huevo');

        // Stock: enough, short and missing cases. Ajo, Queso, Pan de molde and Jamón de York have none.
        $fridge = StorageLocation::Fridge;
        $pantry = StorageLocation::Pantry;
        $stock = [
            ['Arroz', '500', Unit::Gram, $pantry], ['Pasta', '400', Unit::Gram, $pantry],
            ['Pechuga de pollo', '350', Unit::Gram, $fridge], ['Huevos', '6', Unit::Piece, $fridge],
            ['Leche', '1', Unit::Litre, $fridge], ['Tomate', '300', Unit::Gram, $fridge],
            ['Cebolla', '2', Unit::Piece, $pantry], ['Patata', '800', Unit::Gram, $pantry],
            ['Zanahoria', '300', Unit::Gram, $fridge], ['Calabacín', '1', Unit::Piece, $fridge],
            ['Pimiento', '1', Unit::Piece, $fridge], ['Queso rallado', '100', Unit::Gram, $fridge],
            ['Yogur natural', '4', Unit::Piece, $fridge], ['Garbanzos cocidos', '1', Unit::Jar, $pantry],
            ['Tomate frito', '1', Unit::Jar, $pantry], ['Atún en conserva', '2', Unit::Can, $pantry],
            ['Pan', '1', Unit::Piece, $pantry], ['Aceite de oliva', '250', Unit::Millilitre, $pantry],
            ['Sal', '100', Unit::Gram, $pantry],
        ];

        foreach ($stock as [$name, $amount, $unit, $location]) {
            $this->stock->add($household, $ingredients[$name], $location, Quantity::of($amount, $unit));
        }

        // Recipes for two servings.
        $book = [
            'Arroz con pollo y verduras' => [
                ['Arroz', '160', Unit::Gram], ['Pechuga de pollo', '250', Unit::Gram], ['Zanahoria', '1', Unit::Piece],
                ['Calabacín', '0.5', Unit::Piece], ['Cebolla', '0.5', Unit::Piece],
            ],
            'Pasta con tomate y queso' => [
                ['Pasta', '180', Unit::Gram], ['Tomate frito', '200', Unit::Gram], ['Queso rallado', '40', Unit::Gram],
                ['Aceite de oliva', '10', Unit::Millilitre],
            ],
            'Tortilla de patatas' => [
                ['Patata', '400', Unit::Gram], ['Huevos', '4', Unit::Piece], ['Cebolla', '0.5', Unit::Piece],
                ['Aceite de oliva', '15', Unit::Millilitre],
            ],
            'Garbanzos con verduras' => [
                ['Garbanzos cocidos', '1', Unit::Jar], ['Zanahoria', '1', Unit::Piece], ['Patata', '200', Unit::Gram],
                ['Tomate', '100', Unit::Gram], ['Cebolla', '0.5', Unit::Piece],
            ],
            'Revuelto de calabacín y huevo' => [
                ['Calabacín', '1', Unit::Piece], ['Huevos', '3', Unit::Piece], ['Cebolla', '0.5', Unit::Piece],
                ['Aceite de oliva', '10', Unit::Millilitre],
            ],
            'Ensalada de atún y huevo' => [
                ['Atún en conserva', '2', Unit::Can], ['Huevos', '2', Unit::Piece], ['Tomate', '150', Unit::Gram],
                ['Cebolla', '0.5', Unit::Piece],
            ],
        ];

        $recipes = [];

        foreach ($book as $name => $lines) {
            $rows = array_values(array_map(
                fn (array $line) => ['ingredient' => $ingredients[$line[0]], 'quantity' => Quantity::of($line[1], $line[2]), 'note' => null],
                $lines,
            ));

            $recipes[$name] = $this->recipes->save($household, null, ['name' => $name, 'base_servings' => 2, 'instructions' => null], $rows);
        }

        // Draft menu of the current week (Europe/Madrid), with empty slots. Not closed on purpose.
        $menu = $this->planner->findOrCreate($household, Week::current());
        $plan = [
            [1, Meal::Lunch, 'Arroz con pollo y verduras', 2], [1, Meal::Dinner, 'Ensalada de atún y huevo', 2],
            [2, Meal::Lunch, 'Pasta con tomate y queso', 2], [2, Meal::Dinner, 'Revuelto de calabacín y huevo', 2],
            [3, Meal::Lunch, 'Garbanzos con verduras', 2], [4, Meal::Lunch, 'Tortilla de patatas', 2],
            [5, Meal::Lunch, 'Arroz con pollo y verduras', 3],
        ];

        foreach ($plan as [$day, $meal, $recipe, $servings]) {
            $this->planner->add($menu, $day, $meal, DishType::Recipe, $recipes[$recipe], null, $servings);
        }

        $this->planner->add($menu, 3, Meal::Dinner, DishType::Adhoc, null, 'Sandwich Mixto', 2, [
            ['ingredient' => $ingredients['Pan de molde'], 'quantity' => Quantity::of('4', Unit::Slice), 'note' => null],
            ['ingredient' => $ingredients['Jamón de York'], 'quantity' => Quantity::of('4', Unit::DeliSlice), 'note' => null],
            ['ingredient' => $ingredients['Queso'], 'quantity' => Quantity::of('4', Unit::DeliSlice), 'note' => null],
        ]);

        return [
            'ingredients' => $household->ingredients()->count(),
            'ingredient_aliases' => $household->ingredientAliases()->count(),
            'inventory_items' => $household->inventoryItems()->count(),
            'recipes' => $household->recipes()->count(),
            'menus' => $household->menus()->count(),
            'planned_dishes' => $menu->dishes()->count(),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function report(string $title, array $counts): void
    {
        $this->command->info($title.': '.implode(', ', array_map(fn (string $table, int $count) => "{$table}={$count}", array_keys($counts), $counts)));
    }
}
