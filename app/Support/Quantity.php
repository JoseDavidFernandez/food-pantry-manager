<?php

namespace App\Support;

use App\Enums\Unit;
use App\Enums\UnitGroup;
use InvalidArgumentException;

/**
 * Exact decimal quantity with its unit (DATA-MODEL §4 and §5). All arithmetic uses bcmath.
 */
final class Quantity
{
    private const SCALE = 3;

    /**
     * Largest amount the DECIMAL(12,3) quantity columns can store.
     */
    public const MAX_STORABLE = '999999999.999';

    /**
     * @param  numeric-string  $amount
     */
    private function __construct(
        public readonly string $amount,
        public readonly Unit $unit,
    ) {}

    /**
     * @throws InvalidArgumentException when the amount is not a positive decimal (RN-07)
     */
    public static function of(string $amount, Unit $unit): self
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Invalid quantity [{$amount}].");
        }

        $amount = bcadd($amount, '0', self::SCALE);

        if (bccomp($amount, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Quantities must be greater than zero.');
        }

        return new self($amount, $unit);
    }

    /**
     * This quantity, if it fits the quantity columns; checked by the models before saving.
     *
     * @throws QuantityTooLarge
     */
    public function storable(): self
    {
        if (bccomp($this->amount, self::MAX_STORABLE, self::SCALE) > 0) {
            throw new QuantityTooLarge($this);
        }

        return $this;
    }

    /**
     * Combine two quantities of the same compatibility group (RN-06).
     * Same unit: the unit is kept. g with kg (ml with l): kg (l) when the total is at least 1 kg (1 l)
     * and the value is exact with three decimals; otherwise g (ml).
     *
     * @throws IncompatibleUnitsException
     */
    public function plus(self $other): self
    {
        if ($this->unit->group() !== $other->unit->group()) {
            throw new IncompatibleUnitsException($this->unit, $other->unit);
        }

        if ($this->unit === $other->unit) {
            return new self(bcadd($this->amount, $other->amount, self::SCALE), $this->unit);
        }

        return self::fromBase(bcadd($this->inBaseUnit(), $other->inBaseUnit(), self::SCALE), $this->unit->group());
    }

    /**
     * What is missing to reach this quantity with the other one, or null when the other is enough
     * (never negative, RN-07). The result keeps the unit when both use the same one.
     *
     * @throws IncompatibleUnitsException
     */
    public function minus(self $other): ?self
    {
        if ($this->unit->group() !== $other->unit->group()) {
            throw new IncompatibleUnitsException($this->unit, $other->unit);
        }

        if ($this->unit === $other->unit) {
            $rest = bcsub($this->amount, $other->amount, self::SCALE);

            return bccomp($rest, '0', self::SCALE) > 0 ? new self($rest, $this->unit) : null;
        }

        $rest = bcsub($this->inBaseUnit(), $other->inBaseUnit(), self::SCALE);

        return bccomp($rest, '0', self::SCALE) > 0 ? self::fromBase($rest, $this->unit->group()) : null;
    }

    /**
     * Purchase quantity (RN-23): indivisible units are rounded up to a whole number;
     * g, kg, ml and l are left untouched. Recipes and stock are never modified by this.
     */
    public function roundedUpForPurchase(): self
    {
        if (! $this->unit->isIndivisible()) {
            return $this;
        }

        $whole = bcadd($this->amount, '0', 0);

        if (bccomp($this->amount, $whole, self::SCALE) > 0) {
            $whole = bcadd($whole, '1', 0);
        }

        return new self(bcadd($whole, '0', self::SCALE), $this->unit);
    }

    /**
     * Compare two quantities of the same group by their equivalent amount (-1, 0 or 1).
     *
     * @throws IncompatibleUnitsException
     */
    public function compareTo(self $other): int
    {
        if ($this->unit->group() !== $other->unit->group()) {
            throw new IncompatibleUnitsException($this->unit, $other->unit);
        }

        return bccomp($this->inBaseUnit(), $other->inBaseUnit(), self::SCALE);
    }

    /**
     * Scale by planned servings over base servings (RN-13), rounded half up to three decimals
     * and never below the smallest storable amount.
     */
    public function scaled(int $servings, int $baseServings): self
    {
        if ($servings < 1 || $baseServings < 1) {
            throw new InvalidArgumentException('Servings must be positive.');
        }

        $exact = bcdiv(bcmul($this->amount, (string) $servings, self::SCALE), (string) $baseServings, self::SCALE + 3);
        $rounded = bcadd($exact, '0.0005', self::SCALE);

        return new self(bccomp($rounded, '0.001', self::SCALE) < 0 ? '0.001' : $rounded, $this->unit);
    }

    /**
     * Express an amount in the group's base unit (g, ml or the indivisible unit) following RN-06:
     * kg (l) when it is at least 1 kg (1 l) and exact with three decimals; otherwise g (ml).
     *
     * @param  numeric-string  $base
     */
    public static function fromBase(string $base, UnitGroup $group): self
    {
        $large = $group->largeUnit();

        if ($large !== null && bccomp($base, $large->factor(), self::SCALE) >= 0) {
            $inLarge = bcdiv($base, $large->factor(), self::SCALE + 3);

            if (bccomp($inLarge, bcadd($inLarge, '0', self::SCALE), self::SCALE + 3) === 0) {
                return new self(bcadd($inLarge, '0', self::SCALE), $large);
            }
        }

        return new self(bcadd($base, '0', self::SCALE), $group->baseUnit());
    }

    /**
     * @return numeric-string
     */
    public function inBaseUnit(): string
    {
        return bcmul($this->amount, $this->unit->factor(), self::SCALE);
    }

    /**
     * Spanish display without superfluous zeros: "1,5", "200", "0,25".
     */
    public function formattedAmount(): string
    {
        return self::spanish($this->amount);
    }

    public function format(): string
    {
        return $this->formattedAmount().' '.$this->unit->label($this->amount);
    }

    /**
     * Presentation only, for totals and calculations: mass in kg from 1000 g and in g below it,
     * volume in l from 1000 ml and in ml below it ("1 kg", "1,25 kg", "2 l", "500 g"). Exact:
     * three decimals in g are at most six in kg, so nothing is rounded. Indivisible units are
     * shown as they are. The stored quantity never changes.
     */
    public function readable(): string
    {
        $group = $this->unit->group();
        $large = $group->largeUnit();

        if ($large === null) {
            return $this->format();
        }

        $base = $this->inBaseUnit();
        [$amount, $unit] = bccomp($base, $large->factor(), self::SCALE) >= 0
            ? [bcdiv($base, $large->factor(), self::SCALE + 3), $large]
            : [$base, $group->baseUnit()];

        return self::spanish($amount).' '.$unit->label($amount);
    }

    /**
     * @param  numeric-string  $amount
     */
    private static function spanish(string $amount): string
    {
        $formatted = str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;

        return str_replace('.', ',', $formatted);
    }
}
