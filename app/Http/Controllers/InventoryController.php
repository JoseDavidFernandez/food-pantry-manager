<?php

namespace App\Http\Controllers;

use App\Actions\Ingredients\BasicFoods;
use App\Actions\Ingredients\FoodUnitsEditor;
use App\Actions\Ingredients\FoodUnitsRefused;
use App\Actions\Ingredients\IngredientCatalog;
use App\Actions\Inventory\FoodRestock;
use App\Actions\Inventory\StockEstimator;
use App\Actions\Inventory\StockManager;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Enums\UnitGroup;
use App\Http\Requests\Inventory\AdjustInventoryItemRequest;
use App\Http\Requests\Inventory\StoreInventoryItemRequest;
use App\Http\Requests\Inventory\UpdateInventoryItemRequest;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\IngredientConversion;
use App\Models\InventoryItem;
use App\Models\User;
use App\Support\FoodUnits;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use App\Support\Week;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /** Filter value of the «Sin existencias» tab: foods of the catalog with no stock. */
    public const UNSTOCKED = 'none';

    public function __construct(private readonly StockManager $stock) {}

    public function index(Request $request, IngredientCatalog $catalog, StockEstimator $estimator, FoodRestock $restock, BasicFoods $basics): Response
    {
        /** @var User $user */
        $user = $request->user();
        $household = $user->currentHousehold();
        // What is probably at home per row (RN-44), next to what was registered.
        $units = FoodUnitsMap::forHousehold($household);
        $estimate = $estimator->estimate($household, $units)['rows'];

        // After a rejected "add stock", offer ingredients that look like what was typed.
        $typed = $request->old('ingredient');
        $similar = is_string($typed) && $catalog->resolve($household, $typed) === null
            ? $catalog->similar($household, $typed)
            : [];
        $filter = $request->string('location')->toString();
        $location = StorageLocation::tryFrom($filter);
        $unstocked = $filter === self::UNSTOCKED;

        $foodLocations = $unstocked ? [] : $household->inventoryItems()->get()
            ->groupBy('ingredient_id')
            ->map(fn ($rows) => $rows->map(fn (InventoryItem $row) => $row->location->label())->unique()->values()->all())
            ->all();

        // The «Sin existencias» tab only lists foods of the catalog, no stock rows.
        $items = $unstocked ? collect() : $household->inventoryItems()
            ->with('ingredient')
            ->when($location, fn ($query) => $query->where('location', $location))
            ->get()
            ->sortBy([
                fn (InventoryItem $a, InventoryItem $b) => strcoll($a->ingredient->normalized_name, $b->ingredient->normalized_name),
                fn (InventoryItem $a, InventoryItem $b) => strcmp($a->location->value, $b->location->value),
            ])
            ->map(fn (InventoryItem $item) => [
                // «Se acabó» counts every location of the food to zero (RN-47): say which.
                'food_locations' => $foodLocations[$item->ingredient_id] ?? [],
                ...$this->estimatePayload($item, $estimate[StockEstimator::rowKey($item->ingredient_id, $item->location, $item->unit_group)] ?? null, $units),
                'id' => $item->id,
                'ingredient' => $item->ingredient->name,
                'ingredient_id' => $item->ingredient_id,
                ...$item->ingredient->visualPayload(),
                'location' => $item->location->value,
                'location_label' => $item->location->label(),
                'quantity' => $item->toQuantity()->formattedAmount(),
                'unit' => $item->unit->value,
                'unit_label' => $item->unit->label($item->quantity),
                'unit_group' => $item->unit_group->value,
                'label' => $item->label,
                // Quick adjustment: ± one unit only for indivisible units, never down to zero.
                'can_step' => $item->unit->isIndivisible(),
                'can_decrease' => $item->unit->isIndivisible() && bccomp($item->quantity, '1', 3) > 0,
            ])
            ->values();

        $counts = $household->inventoryItems()
            ->selectRaw('location, count(*) as total')
            ->groupBy('location')
            ->pluck('total', 'location');

        $suggestions = $catalog->suggestions($household);

        // The catalog definition of every food (name, category, image, aliases, use), edited
        // from the same screen: there is no separate «Ingredientes» section any more.
        $foods = $household->ingredients()
            ->with(['aliases' => fn ($query) => $query->orderBy('alias'), 'conversions' => fn ($query) => $query->orderBy('id')])
            ->withCount(['inventoryItems', 'recipeIngredients', 'plannedDishIngredients'])
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'default_unit' => $ingredient->default_unit?->value,
                // Unit groups comparable with its usual unit, directly or through its own
                // conversions (RN-06, RN-40): «Añadir al inventario» warns about any other one.
                'unit_groups' => self::comparableGroups($ingredient),
                // What one pack holds when its conversions say so («1 paquete = 1 kg»), per unit.
                'pack_contents' => self::packContents($ingredient),
                'stock_count' => $ingredient->inventory_items_count,
                'recipe_count' => $ingredient->recipe_ingredients_count,
                'in_menus' => $ingredient->planned_dish_ingredients_count > 0,
                ...$ingredient->visualPayload(),
                'aliases' => $ingredient->aliases->map(fn (IngredientAlias $alias) => [
                    'id' => $alias->id,
                    'alias' => $alias->alias,
                ])->values(),
                // Its own units (RN-40, RN-41).
                'purchase_unit' => $ingredient->purchase_unit?->value,
                // RN-47: «Mínimo en casa», or the single restock asked for by «Se acabó».
                'restock' => $ingredient->restock_mode === null ? null : [
                    'mode' => $ingredient->restock_mode,
                    'quantity' => $ingredient->restock_quantity === null ? null : str_replace('.', ',', rtrim(rtrim($ingredient->restock_quantity, '0'), '.')),
                    'unit' => $ingredient->restock_unit?->value,
                    'display' => $restock->active($ingredient)?->format(),
                ],
                'pack' => FoodUnits::from($ingredient->conversions, $ingredient->purchase_unit)->packContent(),
                'conversions' => $ingredient->conversions->map(fn (IngredientConversion $conversion) => [
                    'id' => $conversion->id,
                    'label' => IngredientUnitsController::describe($conversion),
                ])->values(),
            ]);

        return Inertia::render('inventory/Index', [
            'items' => $items,
            'foods' => $foods,
            'filter' => $unstocked ? self::UNSTOCKED : $location?->value,
            'counts' => [
                'all' => (int) $counts->sum(),
                self::UNSTOCKED => $foods->where('stock_count', 0)->count(),
                ...collect(StorageLocation::cases())->mapWithKeys(fn (StorageLocation $location) => [
                    $location->value => (int) ($counts[$location->value] ?? 0),
                ])->all(),
            ],
            'suggestions' => $suggestions,
            // Usual foods to add to the catalog (RN-49): only in «Sin existencias» and for
            // members who can write; viewers only read.
            'basics' => $unstocked && $user->can('household.write') ? $basics->suggestions($household) : [],
            'similar' => $similar,
            'typed' => $similar === [] ? null : $typed,
            'units' => Unit::options(),
            'locations' => StorageLocation::options(),
            'categories' => IngredientCategory::options(),
            'imageSearch' => config('services.ingredient_images.enabled'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function packContents(Ingredient $ingredient): array
    {
        $units = FoodUnits::from($ingredient->conversions, $ingredient->purchase_unit);
        $contents = [];

        foreach (Unit::cases() as $unit) {
            $target = $units->canonicalGroup($unit->group());

            if ($unit->kind() === 'pack' && $target !== $unit->group()) {
                $contents[$unit->value] = $units->convert(Quantity::of('1', $unit), $target)?->readable() ?? '';
            }
        }

        return array_filter($contents);
    }

    /**
     * @return list<string>
     */
    private static function comparableGroups(Ingredient $ingredient): array
    {
        if ($ingredient->default_unit === null) {
            return [];
        }

        $units = FoodUnits::from($ingredient->conversions, $ingredient->purchase_unit);
        $usual = $ingredient->default_unit->group();

        return array_values(array_map(
            fn (UnitGroup $group) => $group->value,
            array_filter(UnitGroup::cases(), fn (UnitGroup $group) => $units->connected($usual, $group)),
        ));
    }

    public function store(StoreInventoryItemRequest $request, IngredientCatalog $catalog, FoodUnitsEditor $units): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $household = $user->currentHousehold();
        $content = $request->packContent();
        $pack = $request->quantity()->unit;

        [$ingredient, $item, $saved] = DB::transaction(function () use ($request, $catalog, $units, $household, $content, $pack) {
            $ingredient = $request->resolvedIngredient()
                ?? $catalog->findOrCreate($household, $request->string('ingredient')->toString(), $request->category());

            // The content of each pack is a conversion of the food (RN-40), saved once: the next
            // time it is already known. With no purchase format yet, the pack becomes it (RN-41).
            $saved = null;

            if ($content !== null) {
                try {
                    $units->addConversion($ingredient, $pack, $content['factor'], $content['unit']);
                } catch (FoodUnitsRefused $refused) {
                    throw ValidationException::withMessages(['content_quantity' => $refused->getMessage()]);
                }

                if ($ingredient->purchase_unit === null) {
                    $units->setPurchaseUnit($ingredient, $pack);
                }

                $saved = '1 '.$pack->label('1').' = '.Quantity::of($content['factor'], $content['unit'])->format();
            }

            return [$ingredient, $this->stock->add($household, $ingredient, $request->location(), $request->quantity(), $request->label()), $saved];
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "{$ingredient->name}: ahora hay {$item->toQuantity()->format()} en {$item->location->label()}."
                .($saved === null ? '' : " Guardado en su ficha: {$saved}."),
        ]);

        return back();
    }

    /**
     * @return array{estimated: Quantity|null, consumed: list<array{dish: string, at: CarbonImmutable, quantity: Quantity}>}|null
     */
    private function rowEstimate(InventoryItem $item): ?array
    {
        $rows = app(StockEstimator::class)->estimate($item->household, FoodUnitsMap::forHousehold($item->household))['rows'];

        return $rows[StockEstimator::rowKey($item->ingredient_id, $item->location, $item->unit_group)] ?? null;
    }

    /**
     * After counting or deleting one location, the other locations of the same food whose
     * estimate differs from what is registered are mentioned once, so they can be counted too
     * if the user wants (RN-44). Nothing has to be done.
     */
    private function otherLocationsNote(int $ingredientId, StorageLocation $counted): string
    {
        $ingredient = Ingredient::query()->findOrFail($ingredientId);
        $units = FoodUnitsMap::forHousehold($ingredient->household);
        $estimate = app(StockEstimator::class)->estimate($ingredient->household, $units)['rows'];
        $notes = [];

        foreach ($ingredient->inventoryItems()->where('location', '!=', $counted)->get() as $other) {
            $row = $this->estimatePayload($other, $estimate[StockEstimator::rowKey($other->ingredient_id, $other->location, $other->unit_group)] ?? null, $units);

            if ($row['estimate'] !== null) {
                $notes[] = "{$other->location->label()}: ≈ {$row['estimate']['display']} previstos (registrado {$row['estimate']['registered']})";
            }
        }

        return $notes === [] ? '' : ' Sigue siendo una estimación en '.mb_strtolower(implode('; ', $notes)).'. Si quieres, cuéntalo también.';
    }

    /**
     * The estimate of a row in its own unit (RN-44): «≈ previsto · registrado» when they differ,
     * with the meals that explain it, and the starting point for a voluntary correction.
     *
     * @param  array{estimated: Quantity|null, consumed: list<array{dish: string, at: CarbonImmutable, quantity: Quantity}>}|null  $row
     * @return array<string, mixed>
     */
    private function estimatePayload(InventoryItem $item, ?array $row, FoodUnitsMap $units): array
    {
        $registered = $item->toQuantity();
        $food = $units->for($item->ingredient_id);
        $estimated = $row === null ? $registered : ($row['estimated'] === null ? null : $food->convert($row['estimated'], $item->unit_group));
        $amount = $estimated === null ? '0' : bcadd(bcdiv($estimated->inBaseUnit(), $item->unit->factor(), 4), '0.0005', 3);
        $differs = bccomp($amount, $registered->amount, 3) !== 0;

        return [
            'estimate' => ! $differs ? null : [
                'quantity' => str_replace('.', ',', rtrim(rtrim($amount, '0'), '.') ?: '0'),
                'display' => $estimated === null ? '0 '.$item->unit->label('0') : $estimated->readable(),
                'registered' => $registered->format(),
                'can_decrease' => $item->unit->isIndivisible() && bccomp($amount, '1', 3) > 0,
                'consumed' => array_map(fn (array $use) => [
                    'dish' => $use['dish'],
                    'when' => Week::spanish($use['at']->timezone(Week::timezone()), 'dddd D').', '.($use['at']->timezone(Week::timezone())->format('H') < '18' ? 'comida' : 'cena'),
                    'quantity' => ($food->convert($use['quantity'], $item->unit_group) ?? $use['quantity'])->readable(),
                ], $row['consumed'] ?? []),
            ],
        ];
    }

    public function update(UpdateInventoryItemRequest $request, InventoryItem $inventory): RedirectResponse
    {
        // The dialog shows the estimate when there is one (RN-44). Saving the quantity shown is
        // not a count: only the location or the label changed, and what is registered stays.
        // Any other quantity is a count by the user (of the moved part, if it is also moved).
        $payload = $this->estimatePayload($inventory, $this->rowEstimate($inventory), FoodUnitsMap::forHousehold($inventory->household))['estimate'];
        $shown = $payload === null ? $inventory->quantity : str_replace(',', '.', $payload['quantity']);
        $stated = bcdiv($request->quantity()->inBaseUnit(), $inventory->unit->factor(), 3);
        $counted = bccomp($stated, is_numeric($shown) ? $shown : '0', 3) !== 0;
        $item = $this->stock->update($inventory, $counted ? $request->quantity() : $inventory->toQuantity(), $request->location(), $request->label(), counted: $counted);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Existencias actualizadas.'.$this->otherLocationsNote($item->ingredient_id, $item->location)]);

        return back();
    }

    public function adjust(AdjustInventoryItemRequest $request, InventoryItem $inventory): RedirectResponse
    {
        // The quick adjustment always states the quantity: it is a count (RN-44).
        $item = $this->stock->update($inventory, $request->quantity(), $inventory->location, $inventory->label, counted: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$item->ingredient->name}: ahora hay {$item->toQuantity()->format()}.".$this->otherLocationsNote($item->ingredient_id, $item->location)]);

        return back();
    }

    public function destroy(InventoryItem $inventory): RedirectResponse
    {
        Gate::authorize('delete', $inventory);

        $this->stock->remove($inventory);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Existencias eliminadas.'.$this->otherLocationsNote($inventory->ingredient_id, $inventory->location)]);

        return back();
    }
}
