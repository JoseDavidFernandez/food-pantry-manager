<?php

namespace App\Support;

use App\Enums\Unit;
use App\Enums\UnitGroup;
use App\Models\IngredientConversion;

/**
 * The units of one food (RN-40, DATA-MODEL §4.1): the universal groups (g↔kg, ml↔l, each
 * indivisible unit on its own) joined by the food's own conversions ("1 cabeza = 10 dientes",
 * "1 bolsa = 500 g"). Conversions form a tree: two groups are joined by one path at most, so a
 * quantity always converts the same way and there are no contradictions.
 *
 * Needs and stock of the food are compared in the canonical unit of each joined set of groups:
 * mass, then volume, otherwise the smallest unit. With a purchase unit, what to buy is rounded up
 * to whole packs (RN-41).
 */
final class FoodUnits
{
    /** Internal precision of the ratios between groups. */
    private const RATIO_SCALE = 12;

    /**
     * @param  array<string, numeric-string>  $values  per group: base units of its component root in one base unit of the group
     * @param  array<string, int>  $components  per group: its component
     */
    private function __construct(
        private readonly array $values,
        private readonly array $components,
        public readonly ?Unit $purchaseUnit,
    ) {}

    public static function none(): self
    {
        return new self([], [], null);
    }

    /**
     * @param  iterable<IngredientConversion>  $conversions
     */
    public static function from(iterable $conversions, ?Unit $purchaseUnit = null): self
    {
        /** @var array<string, list<array{0: string, 1: numeric-string}>> $edges */
        $edges = [];

        foreach ($conversions as $conversion) {
            // 1 unit = factor to_unit, so one base unit of its group is `ratio` base units of the other.
            $ratio = bcdiv(bcmul($conversion->factor, $conversion->to_unit->factor(), self::RATIO_SCALE), $conversion->unit->factor(), self::RATIO_SCALE);
            $from = $conversion->unit->group()->value;
            $to = $conversion->to_unit->group()->value;

            if ($from === $to || bccomp($ratio, '0', self::RATIO_SCALE) <= 0) {
                continue;
            }

            $edges[$from][] = [$to, $ratio];
            $edges[$to][] = [$from, bcdiv('1', $ratio, self::RATIO_SCALE)];
        }

        $values = [];
        $components = [];
        $component = 0;

        foreach (array_keys($edges) as $root) {
            if (isset($values[$root])) {
                continue;
            }

            $values[$root] = '1';
            $components[$root] = ++$component;
            $queue = [$root];

            while ($queue !== []) {
                $group = array_shift($queue);

                foreach ($edges[$group] as [$next, $ratio]) {
                    // A second path to a group already reached is ignored (the tree is enforced on save).
                    if (! isset($values[$next])) {
                        // 1 base of `group` = ratio bases of `next`, so one base of `next` is value/ratio roots.
                        $values[$next] = bcdiv($values[$group], $ratio, self::RATIO_SCALE);
                        $components[$next] = $component;
                        $queue[] = $next;
                    }
                }
            }
        }

        return new self($values, $components, $purchaseUnit);
    }

    public function hasConversions(): bool
    {
        return $this->components !== [];
    }

    public function connected(UnitGroup $a, UnitGroup $b): bool
    {
        return $a === $b || (isset($this->components[$a->value], $this->components[$b->value])
            && $this->components[$a->value] === $this->components[$b->value]);
    }

    /**
     * The group needs and stock of this group are compared in.
     */
    public function canonicalGroup(UnitGroup $group): UnitGroup
    {
        if (! isset($this->components[$group->value])) {
            return $group;
        }

        $members = array_keys(array_filter($this->components, fn (int $c) => $c === $this->components[$group->value]));

        foreach ([UnitGroup::Mass, UnitGroup::Volume] as $preferred) {
            if (in_array($preferred->value, $members, true)) {
                return $preferred;
            }
        }

        // The smallest unit: the fewest root units per base unit (ties: the first group declared).
        $best = null;

        foreach (UnitGroup::cases() as $candidate) {
            if (in_array($candidate->value, $members, true)
                && ($best === null || bccomp($this->values[$candidate->value], $this->values[$best->value], self::RATIO_SCALE) < 0)) {
                $best = $candidate;
            }
        }

        return $best ?? $group;
    }

    public function normalize(Quantity $quantity): Quantity
    {
        return $this->convert($quantity, $this->canonicalGroup($quantity->unit->group())) ?? $quantity;
    }

    /**
     * The same quantity in another group, or null when this food has no way to convert it.
     * Rounded half up to three decimals, never below the smallest storable amount.
     */
    public function convert(Quantity $quantity, UnitGroup $to): ?Quantity
    {
        $from = $quantity->unit->group();

        if ($from === $to) {
            return $quantity;
        }

        if (! $this->connected($from, $to)) {
            return null;
        }

        $base = $this->inGroupBase($quantity, $to);
        $rounded = bcadd($base, '0.0005', 3);

        return Quantity::fromBase(bccomp($rounded, '0.001', 3) < 0 ? '0.001' : $rounded, $to);
    }

    /**
     * What to buy in whole packs of the purchase unit (RN-41), or null when the food has none
     * (or it is not convertible): then RN-23 applies.
     */
    public function forPurchase(Quantity $quantity): ?Quantity
    {
        $unit = $this->purchaseUnit;

        if ($unit === null || ! $this->connected($quantity->unit->group(), $unit->group())) {
            return null;
        }

        // Six decimals first, so that a ratio like 1/3 never rounds 3 packs up to 4.
        $packs = bcadd(bcdiv($this->inGroupBase($quantity, $unit->group()), $unit->factor(), self::RATIO_SCALE), '0.0000005', 6);
        $whole = bcadd($packs, '0', 0);

        if (bccomp($packs, $whole, 6) > 0) {
            $whole = bcadd($whole, '1', 0);
        }

        return Quantity::of($whole, $unit);
    }

    /**
     * What one pack holds, to explain the rounding ("1 bolsa = 500 g"); null without a purchase
     * unit or when it is the canonical unit itself.
     */
    public function packContent(): ?string
    {
        $unit = $this->purchaseUnit;

        if ($unit === null) {
            return null;
        }

        $canonical = $this->canonicalGroup($unit->group());

        if ($canonical === $unit->group()) {
            return null;
        }

        $content = $this->convert(Quantity::of('1', $unit), $canonical);

        return $content === null ? null : '1 '.$unit->label('1').' = '.$content->readable();
    }

    /**
     * @return numeric-string amount in base units of the target group
     */
    private function inGroupBase(Quantity $quantity, UnitGroup $to): string
    {
        $from = $quantity->unit->group();
        $ratio = bcdiv($this->values[$from->value], $this->values[$to->value], self::RATIO_SCALE);

        return bcmul($quantity->inBaseUnit(), $ratio, self::RATIO_SCALE);
    }
}
