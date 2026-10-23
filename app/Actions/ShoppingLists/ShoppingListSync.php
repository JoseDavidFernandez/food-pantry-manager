<?php

namespace App\Actions\ShoppingLists;

use App\Actions\Menus\PeriodRequirements;
use App\Actions\Receptions\ReceptionProgress;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\ReceptionItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Support\FoodUnitsMap;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Persistent shopping list of a purchase period (RN-36). It replaces closing the weekly menu:
 * every time the list is opened it is reconciled with the current needs (menu, recipes,
 * «No comido» and stock), keeping manual lines, quantities edited by the user and purchases
 * that are still valid (RN-27, RN-28). Stock is never modified (RN-09, RN-25).
 *
 * The same period always returns the same list. A new period starts from the lines of the
 * latest list that is still current and overlaps it, which is kept untouched as history.
 */
class ShoppingListSync
{
    public function __construct(
        private readonly PeriodRequirements $requirements,
        private readonly ReceptionProgress $progress,
    ) {}

    /**
     * The list of a quick period («Próximos 7/14/30 días»): one per household and length, whose
     * window moves to start today when it is opened, keeping its manual lines, purchases and
     * receptions (what belonged to days already past is reconciled like any other change).
     */
    public function forRolling(Household $household, int $days): ShoppingList
    {
        $start = PeriodRequirements::today();
        $end = $start->addDays($days - 1);
        $list = $household->shoppingLists()->where('rolling_days', $days)->first();

        if ($list === null) {
            $list = $this->createRolling($household, $days, $start, $end);
        } elseif ($list->period_start?->format('Y-m-d') !== $start->format('Y-m-d')) {
            // A custom list may already have exactly these dates (unique per period): that one
            // is opened today and the quick list moves on another day.
            $taken = $this->find($household, $start, $end);

            if ($taken !== null) {
                return $this->forPeriod($household, $start, $end);
            }

            $list->update(['period_start' => $start->format('Y-m-d'), 'period_end' => $end->format('Y-m-d')]);
        }

        return $this->reconcileFor($household, $list, $start, $end);
    }

    /**
     * Reconcile a current list with its own period (before receiving a purchase, for example).
     */
    public function refresh(Household $household, ShoppingList $list): ShoppingList
    {
        if ($list->rolling_days !== null) {
            return $this->forRolling($household, $list->rolling_days);
        }

        [$start, $end] = $list->range();

        return $this->forPeriod($household, $start, $end);
    }

    public function forPeriod(Household $household, CarbonImmutable $start, CarbonImmutable $end): ShoppingList
    {
        return $this->reconcileFor($household, $this->findOrCreate($household, $start, $end), $start, $end);
    }

    private function reconcileFor(Household $household, ShoppingList $list, CarbonImmutable $start, CarbonImmutable $end): ShoppingList
    {
        DB::transaction(function () use ($household, $list, $start, $end) {
            // Two tabs opening the list at once reconcile it one after the other, never twice in parallel.
            ShoppingList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            $this->reconcile($list, $this->targets($this->requirements->calculate($household, $start, $end)));
            $list->update(['regenerated_at' => now()]);
        });

        return $list->refresh();
    }

    private function findOrCreate(Household $household, CarbonImmutable $start, CarbonImmutable $end): ShoppingList
    {
        $existing = $this->find($household, $start, $end);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($household, $start, $end) {
                $list = $household->shoppingLists()->create([
                    'period_start' => $start->format('Y-m-d'),
                    'period_end' => $end->format('Y-m-d'),
                    'generated_at' => now(),
                ]);
                $this->seed($household, $list, $start, $end);

                return $list;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Another request created it first (unique household + period).
            return $this->find($household, $start, $end) ?? throw $exception;
        }
    }

    private function createRolling(Household $household, int $days, CarbonImmutable $start, CarbonImmutable $end): ShoppingList
    {
        // The list of today's dates already exists (opened before quick lists existed, or as
        // custom dates): it becomes the quick list, with everything it has.
        $existing = $this->find($household, $start, $end);

        if ($existing !== null) {
            $existing->update(['rolling_days' => $days]);

            return $existing;
        }

        try {
            return DB::transaction(function () use ($household, $days, $start, $end) {
                $list = $household->shoppingLists()->create([
                    'period_start' => $start->format('Y-m-d'),
                    'period_end' => $end->format('Y-m-d'),
                    'rolling_days' => $days,
                    'generated_at' => now(),
                ]);
                $this->seed($household, $list, $start, $end);

                return $list;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Another request created it first (unique household + quick period).
            return $household->shoppingLists()->where('rolling_days', $days)->first() ?? throw $exception;
        }
    }

    private function find(Household $household, CarbonImmutable $start, CarbonImmutable $end): ?ShoppingList
    {
        return $household->shoppingLists()
            ->whereDate('period_start', $start->format('Y-m-d'))
            ->whereDate('period_end', $end->format('Y-m-d'))
            ->first();
    }

    /**
     * Carries the user's decisions into a new period: manual lines, edited quantities, removed
     * lines and purchases of the latest list that is still current and overlaps the period
     * (an old weekly list too). Lines already received are not carried: that stock is counted
     * now. The source list is not modified. The reconciliation that follows applies RN-27/RN-28.
     */
    private function seed(Household $household, ShoppingList $list, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $today = PeriodRequirements::today()->format('Y-m-d');

        $source = $household->shoppingLists()->with('menu')->whereKeyNot($list->id)->orderByDesc('id')->get()
            ->first(function (ShoppingList $candidate) use ($start, $end, $today) {
                [$from, $to] = $candidate->range();

                return $to->format('Y-m-d') >= $today
                    && $from->format('Y-m-d') <= $end->format('Y-m-d')
                    && $to->format('Y-m-d') >= $start->format('Y-m-d');
            });

        if ($source === null) {
            return;
        }

        foreach ($this->progress->forList($source) as $line) {
            $item = $line['item'];

            if (! $line['pending']) {
                continue;
            }

            $copy = $item->replicate(['shopping_list_id', 'receptions_counted_after']);
            $copy->shopping_list_id = $list->id;

            // Its receptions stay in the source list: the copy asks for what is still missing.
            $received = $this->receivedIn($line['received'], $item->toQuantity() ?? $item->calculated());

            if ($received !== null) {
                $copy->setQuantity($line['remaining']);
                $copy->setCalculated($this->minusReceived($item->calculated(), $received));
            }

            $copy->save();
        }

        // Removed generated lines are kept removed (forList skips them).
        foreach ($source->items()->where('is_dismissed', true)->get() as $item) {
            $copy = $item->replicate(['shopping_list_id', 'receptions_counted_after']);
            $copy->shopping_list_id = $list->id;
            $copy->save();
        }
    }

    /**
     * What to buy per compatible key (RN-21 to RN-23 through RN-36): the period calculation
     * already subtracts the stock and rounds up indivisible units.
     *
     * @param  list<array{key: string, ingredient: Ingredient, to_buy: Quantity|null, review: bool}>  $lines
     * @return array<string, array{ingredient: Ingredient, quantity: Quantity, incompatible: bool}>
     */
    private function targets(array $lines): array
    {
        $targets = [];

        foreach ($lines as $line) {
            if ($line['to_buy'] !== null) {
                $targets[$line['key']] = [
                    'ingredient' => $line['ingredient'],
                    'quantity' => $line['to_buy'],
                    'incompatible' => $line['review'],
                ];
            }
        }

        return $targets;
    }

    /**
     * @param  array{ingredient: Ingredient, quantity: Quantity, incompatible: bool}  $target
     */
    private function createGenerated(ShoppingList $list, string $key, array $target, ?int $lastReception = null): void
    {
        $item = new ShoppingListItem([
            'shopping_list_id' => $list->id,
            'origin' => ShoppingListItem::GENERATED,
            'ingredient_id' => $target['ingredient']->id,
            'name' => $target['ingredient']->name,
            'match_key' => $key,
            'receptions_counted_after' => $lastReception,
        ]);
        $item->setQuantity($target['quantity']);
        $item->setCalculated($target['quantity']);

        if ($target['incompatible']) {
            $item->flagForReview(ShoppingListEditor::REVIEW_INCOMPATIBLE);
        }

        $item->save();
    }

    /**
     * Line-by-line reconciliation with the current needs (RN-27, RN-28; DATA-MODEL §6.2 step 6).
     * "Changed" compares the new calculation with the previous `calculated_quantity`.
     *
     * @param  array<string, array{ingredient: Ingredient, quantity: Quantity, incompatible: bool}>  $targets
     */
    private function reconcile(ShoppingList $list, array $targets): void
    {
        $existing = $list->items()->whereNotNull('match_key')->get();
        $units = FoodUnitsMap::forHousehold($list->household);
        // What arrived for each line, as counted against its quantity (before any change here).
        $progress = $this->progress->forList($list);

        // The new calculation subtracts the current stock, which already includes everything
        // received so far: a line set from it only counts later receptions (DATA-MODEL §6.4).
        $lastReception = $list->receptions()->max('id');
        $lastReception = $lastReception === null ? null : (int) $lastReception;

        foreach ($existing as $item) {
            /** @var string $key */
            $key = $item->match_key;
            $target = $targets[$key] ?? null;
            $new = $target['quantity'] ?? null;
            $changed = ! $this->same($item->calculated(), $new);
            unset($targets[$key]);

            // Manual line: kept intact; flagged when the menu also needs it (no duplicate line).
            if (! $item->isGenerated()) {
                if ($new !== null) {
                    $item->setCalculated($new);
                    $item->flagForReview(ShoppingListEditor::REVIEW_MANUAL_CALCULATED);
                    $item->save();
                } elseif ($item->calculated_quantity !== null) {
                    // The menu no longer needs it: the line stays intact, without the stale calculation.
                    $item->setCalculated(null);

                    if ($item->review_reason === ShoppingListEditor::REVIEW_MANUAL_CALCULATED) {
                        $item->needs_review = false;
                        $item->review_reason = null;
                    }

                    $item->save();
                }

                continue;
            }

            // Dismissed by the user: reappears only when the calculation changes.
            if ($item->is_dismissed) {
                if ($new !== null && $changed) {
                    $item->is_dismissed = false;
                    $item->is_user_modified = false;
                    $item->is_purchased = false;
                    $item->setQuantity($new);
                    $item->setCalculated($new);
                    $item->receptions_counted_after = $lastReception;
                    $item->flagForReview(ShoppingListEditor::REVIEW_CHANGED);
                } elseif ($new === null) {
                    $item->setCalculated(null);
                }

                $item->save();

                continue;
            }

            // Modified by the user: the user's quantity is kept; flagged when the plan or the stock
            // changes. What was received for this line raises the stock and lowers the new
            // calculation by the same amount: that is not a change, so it is compared against the
            // previous calculation minus what was received (`calculated_quantity` keeps the need
            // without those receptions, like the user's quantity they are counted against).
            if ($item->is_user_modified) {
                $received = $this->receivedIn($progress[$item->id]['received'] ?? [], $item->calculated() ?? $new);

                if (! $this->same($this->minusReceived($item->calculated(), $received), $new)) {
                    // The purchase unit of the food may have changed (RN-41): what was received is
                    // added in the new unit when the food converts it.
                    $receivedAsNew = $received === null || $new === null ? null : $units->for($item->ingredient_id)->convert($received, $new->unit->group());
                    $item->setCalculated($new === null ? null : ($receivedAsNew === null ? $new : $new->plus($receivedAsNew)));
                    $item->flagForReview($new === null ? ShoppingListEditor::REVIEW_NOT_NEEDED : ShoppingListEditor::REVIEW_CHANGED);
                    $item->save();
                }

                continue;
            }

            // Untouched generated line. Bought and already received: done, the reception keeps it.
            if ($new === null) {
                if ($item->is_purchased && ! $item->receptionItems()->where('outcome', ReceptionItem::RECEIVED)->exists()) {
                    $item->setCalculated(null);
                    $item->flagForReview(ShoppingListEditor::REVIEW_NOT_NEEDED);
                    $item->save();
                } else {
                    $item->delete();
                }

                continue;
            }

            $old = $item->toQuantity();

            // Needed again after being flagged as no longer needed.
            if ($item->review_reason === ShoppingListEditor::REVIEW_NOT_NEEDED) {
                $item->needs_review = false;
                $item->review_reason = null;
            }

            // RN-28: a purchased line stays purchased unless the quantity increases.
            if ($item->is_purchased && $old !== null && $new->compareTo($old) > 0) {
                $item->is_purchased = false;
                $item->flagForReview(ShoppingListEditor::REVIEW_INCREASED);
            }

            $item->setQuantity($new);
            $item->setCalculated($new);
            $item->receptions_counted_after = $lastReception;

            if ($target['incompatible'] ?? false) {
                $item->flagForReview(ShoppingListEditor::REVIEW_INCOMPATIBLE);
            } elseif ($item->review_reason === ShoppingListEditor::REVIEW_INCOMPATIBLE) {
                $item->needs_review = false;
                $item->review_reason = null;
            }

            $item->save();
        }

        foreach ($targets as $key => $target) {
            $this->createGenerated($list, $key, $target, $lastReception);
        }
    }

    /**
     * Sum of the received quantities in the unit group of $like (other groups never count, RN-22).
     *
     * @param  list<Quantity>  $received
     */
    private function receivedIn(array $received, ?Quantity $like): ?Quantity
    {
        $sum = null;

        foreach ($received as $quantity) {
            if ($like !== null && $quantity->unit->group() === $like->unit->group()) {
                $sum = $sum === null ? $quantity : $sum->plus($quantity);
            }
        }

        return $sum;
    }

    private function minusReceived(?Quantity $quantity, ?Quantity $received): ?Quantity
    {
        return $quantity === null || $received === null ? $quantity : $quantity->minus($received);
    }

    private function same(?Quantity $a, ?Quantity $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->unit->group() === $b->unit->group() && $a->compareTo($b) === 0;
    }
}
