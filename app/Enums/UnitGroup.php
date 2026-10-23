<?php

namespace App\Enums;

/**
 * Compatibility groups (DATA-MODEL §4). Only mass and volume contain more than one unit.
 */
enum UnitGroup: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Piece = 'piece';
    case Can = 'can';
    case Jar = 'jar';
    case Pack = 'pack';
    case Slice = 'slice';
    case DeliSlice = 'deli_slice';
    case Tray = 'tray';
    case Clove = 'clove';
    case Head = 'head';
    case Bag = 'bag';
    case Box = 'box';
    case Net = 'net';
    case Carton = 'carton';

    /**
     * The smallest unit of the group, used to combine g with kg or ml with l.
     */
    public function baseUnit(): Unit
    {
        return match ($this) {
            self::Mass => Unit::Gram,
            self::Volume => Unit::Millilitre,
            self::Piece => Unit::Piece,
            self::Can => Unit::Can,
            self::Jar => Unit::Jar,
            self::Pack => Unit::Pack,
            self::Slice => Unit::Slice,
            self::DeliSlice => Unit::DeliSlice,
            self::Tray => Unit::Tray,
            self::Clove => Unit::Clove,
            self::Head => Unit::Head,
            self::Bag => Unit::Bag,
            self::Box => Unit::Box,
            self::Net => Unit::Net,
            self::Carton => Unit::Carton,
        };
    }

    /**
     * The larger unit (1000 base units), if the group has one.
     */
    public function largeUnit(): ?Unit
    {
        return match ($this) {
            self::Mass => Unit::Kilogram,
            self::Volume => Unit::Litre,
            default => null,
        };
    }

    /**
     * @return list<Unit>
     */
    public function units(): array
    {
        return array_values(array_filter(Unit::cases(), fn (Unit $unit) => $unit->group() === $this));
    }
}
