<?php

namespace App\Enums;

/**
 * Units (RN-05, DATA-MODEL §4). Only g↔kg and ml↔l convert universally (RN-06); every other unit
 * is its own group. A food may define its own conversions between groups (1 cabeza = 10
 * dientes, 1 bolsa = 500 g; RN-40), which never apply to other foods.
 */
enum Unit: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Millilitre = 'ml';
    case Litre = 'l';
    case Piece = 'piece';
    case Can = 'can';
    case Jar = 'jar';
    case Pack = 'pack';
    case Slice = 'slice';
    case DeliSlice = 'deli_slice';
    case Tray = 'tray';
    // Added on 2026-10-22 for recipe and purchase units (RN-40).
    case Clove = 'clove';
    case Head = 'head';
    case Bag = 'bag';
    case Box = 'box';
    case Net = 'net';
    case Carton = 'carton';

    public function group(): UnitGroup
    {
        return match ($this) {
            self::Gram, self::Kilogram => UnitGroup::Mass,
            self::Millilitre, self::Litre => UnitGroup::Volume,
            self::Piece => UnitGroup::Piece,
            self::Can => UnitGroup::Can,
            self::Jar => UnitGroup::Jar,
            self::Pack => UnitGroup::Pack,
            self::Slice => UnitGroup::Slice,
            self::DeliSlice => UnitGroup::DeliSlice,
            self::Tray => UnitGroup::Tray,
            self::Clove => UnitGroup::Clove,
            self::Head => UnitGroup::Head,
            self::Bag => UnitGroup::Bag,
            self::Box => UnitGroup::Box,
            self::Net => UnitGroup::Net,
            self::Carton => UnitGroup::Carton,
        };
    }

    /**
     * Pieces, cans, jars, packs, slices and trays are bought whole (RN-23). There are no equivalences
     * between them or with mass/volume in the MVP (RN-06).
     */
    public function isIndivisible(): bool
    {
        return ! in_array($this->group(), [UnitGroup::Mass, UnitGroup::Volume], true);
    }

    /**
     * How many base units of the group one unit represents.
     *
     * @return numeric-string
     */
    public function factor(): string
    {
        return match ($this) {
            self::Kilogram, self::Litre => '1000',
            default => '1',
        };
    }

    /**
     * Spanish label, singular when the amount is exactly one.
     *
     * @param  numeric-string  $amount
     */
    public function label(string $amount = '2'): string
    {
        $singular = bccomp($amount, '1', 3) === 0;

        return match ($this) {
            self::Gram, self::Kilogram, self::Millilitre, self::Litre => $this->value,
            self::Piece => $singular ? 'unidad' : 'unidades',
            self::Can => $singular ? 'lata' : 'latas',
            self::Jar => $singular ? 'bote' : 'botes',
            self::Pack => $singular ? 'paquete' : 'paquetes',
            self::Slice => $singular ? 'rebanada' : 'rebanadas',
            self::DeliSlice => $singular ? 'loncha' : 'lonchas',
            self::Tray => $singular ? 'bandeja' : 'bandejas',
            self::Clove => $singular ? 'diente' : 'dientes',
            self::Head => $singular ? 'cabeza' : 'cabezas',
            self::Bag => $singular ? 'bolsa' : 'bolsas',
            self::Box => $singular ? 'caja' : 'cajas',
            self::Net => $singular ? 'malla' : 'mallas',
            self::Carton => $singular ? 'cartón' : 'cartones',
        };
    }

    /**
     * How the unit is shown in selectors (2026-10-28): measures (g, kg, ml, l), pieces counted
     * one by one (unidad, rebanada, loncha, diente, cabeza) and the packs a food is bought in.
     */
    public function kind(): string
    {
        return match ($this) {
            self::Gram, self::Kilogram, self::Millilitre, self::Litre => 'measure',
            self::Piece, self::Slice, self::DeliSlice, self::Clove, self::Head => 'piece',
            default => 'pack',
        };
    }

    /**
     * Bandeja, caja and malla are no longer offered for new entries (2026-10-28): paquete and
     * bolsa cover them. They stay valid, so existing foods, stock and recipes keep working.
     */
    public function isOffered(): bool
    {
        return ! in_array($this, [self::Tray, self::Box, self::Net], true);
    }

    /**
     * @return list<array{value: string, label: string, group: string, kind: string, offered: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $unit) => [
            'value' => $unit->value,
            'label' => $unit->label(),
            'group' => $unit->group()->value,
            'kind' => $unit->kind(),
            'offered' => $unit->isOffered(),
        ], self::cases());
    }
}
