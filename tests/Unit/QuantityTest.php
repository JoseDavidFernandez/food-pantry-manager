<?php

use App\Enums\Unit;
use App\Support\IncompatibleUnitsException;
use App\Support\Quantity;

function combined(string $a, Unit $ua, string $b, Unit $ub): string
{
    $q = Quantity::of($a, $ua)->plus(Quantity::of($b, $ub));

    return $q->amount.' '.$q->unit->value;
}

test('CA-04: 500 g + 1 kg = 1.5 kg', function () {
    expect(combined('500', Unit::Gram, '1', Unit::Kilogram))->toBe('1.500 kg');
});

test('CA-04: 200 g + 300 g = 500 g (same unit is kept)', function () {
    expect(combined('200', Unit::Gram, '300', Unit::Gram))->toBe('500.000 g');
});

test('CA-04: 2 packs + 1 pack = 3 packs, never grams', function () {
    expect(combined('2', Unit::Pack, '1', Unit::Pack))->toBe('3.000 pack');
});

test('RN-06: mixed units below 1 kg / 1 l are expressed in g / ml', function () {
    expect(combined('0.2', Unit::Kilogram, '300', Unit::Gram))->toBe('500.000 g')
        ->and(combined('250', Unit::Millilitre, '0.75', Unit::Litre))->toBe('1.000 l');
});

test('mixed units keep grams when kilograms would lose precision', function () {
    expect(combined('1000.5', Unit::Gram, '0.001', Unit::Kilogram))->toBe('1001.500 g');
});

test('RN-06: incompatible units are never combined', function () {
    Quantity::of('1', Unit::Jar)->plus(Quantity::of('200', Unit::Gram));
})->throws(IncompatibleUnitsException::class);

test('RN-07: quantities must be greater than zero', function (string $amount) {
    Quantity::of($amount, Unit::Gram);
})->with(['0', '-1', 'abc'])->throws(InvalidArgumentException::class);

test('CA-05: 200 g for 4 servings planned for 6 servings is 300 g', function () {
    $scaled = Quantity::of('200', Unit::Gram)->scaled(6, 4);

    expect($scaled->amount.' '.$scaled->unit->value)->toBe('300.000 g');
});

test('scaling rounds half up to three decimals and keeps indivisible units', function () {
    expect(Quantity::of('1', Unit::Jar)->scaled(1, 3)->amount)->toBe('0.333')
        ->and(Quantity::of('2', Unit::Jar)->scaled(1, 3)->amount)->toBe('0.667')
        ->and(Quantity::of('1', Unit::Jar)->scaled(1, 3)->unit)->toBe(Unit::Jar);
});

test('minus returns what is missing, combining g and kg, or null when there is enough', function () {
    $need = Quantity::of('1', Unit::Kilogram);

    expect($need->minus(Quantity::of('400', Unit::Gram))?->format())->toBe('600 g')
        ->and($need->minus(Quantity::of('2', Unit::Kilogram)))->toBeNull()
        ->and(Quantity::of('3', Unit::Piece)->minus(Quantity::of('2', Unit::Piece))?->format())->toBe('1 unidad');
});

test('minus never mixes incompatible units', function () {
    Quantity::of('300', Unit::Gram)->minus(Quantity::of('1', Unit::Jar));
})->throws(IncompatibleUnitsException::class);

test('quantities are formatted the Spanish way', function () {
    expect(Quantity::of('1.5', Unit::Kilogram)->format())->toBe('1,5 kg')
        ->and(Quantity::of('200', Unit::Gram)->format())->toBe('200 g')
        ->and(Quantity::of('1', Unit::Jar)->format())->toBe('1 bote')
        ->and(Quantity::of('3', Unit::Jar)->format())->toBe('3 botes');
});

test('RN-23: indivisible units are rounded up for purchase', function (string $amount, Unit $unit, string $expected) {
    $q = Quantity::of($amount, $unit)->roundedUpForPurchase();

    expect($q->amount.' '.$q->unit->value)->toBe($expected);
})->with([
    'jars' => ['1.5', Unit::Jar, '2.000 jar'],
    'pieces' => ['2.001', Unit::Piece, '3.000 piece'],
    'packs, already whole' => ['3', Unit::Pack, '3.000 pack'],
    'trays' => ['0.25', Unit::Tray, '1.000 tray'],
    'grams are not rounded' => ['612.5', Unit::Gram, '612.500 g'],
    'litres are not rounded' => ['1.25', Unit::Litre, '1.250 l'],
]);

test('quantities are compared in their base unit', function () {
    expect(Quantity::of('1', Unit::Kilogram)->compareTo(Quantity::of('1000', Unit::Gram)))->toBe(0)
        ->and(Quantity::of('900', Unit::Gram)->compareTo(Quantity::of('1', Unit::Kilogram)))->toBe(-1)
        ->and(Quantity::of('1.5', Unit::Litre)->compareTo(Quantity::of('1499', Unit::Millilitre)))->toBe(1);
});

test('quantities of different groups cannot be compared', function () {
    Quantity::of('1', Unit::Jar)->compareTo(Quantity::of('300', Unit::Gram));
})->throws(IncompatibleUnitsException::class);

test('cans, slices and deli slices only combine with themselves', function (Unit $unit, string $expected) {
    $q = Quantity::of('2', $unit)->plus(Quantity::of('1', $unit));

    expect($q->format())->toBe($expected);
})->with([
    'cans' => [Unit::Can, '3 latas'],
    'slices' => [Unit::Slice, '3 rebanadas'],
    'deli slices' => [Unit::DeliSlice, '3 lonchas'],
]);

test('cans, slices and deli slices are never converted to grams, millilitres or another unit', function (Unit $a, Unit $b) {
    Quantity::of('1', $a)->plus(Quantity::of('1', $b));
})->with([
    'can + g' => [Unit::Can, Unit::Gram],
    'can + ml' => [Unit::Can, Unit::Millilitre],
    'can + jar' => [Unit::Can, Unit::Jar],
    'slice + deli slice' => [Unit::Slice, Unit::DeliSlice],
    'slice + piece' => [Unit::Slice, Unit::Piece],
    'deli slice + g' => [Unit::DeliSlice, Unit::Gram],
    'pack + piece' => [Unit::Pack, Unit::Piece],
])->throws(IncompatibleUnitsException::class);

test('the new units are indivisible: rounded up for purchase and labelled in Spanish', function () {
    expect(Quantity::of('1.5', Unit::Can)->roundedUpForPurchase()->format())->toBe('2 latas')
        ->and(Quantity::of('0.5', Unit::Slice)->roundedUpForPurchase()->format())->toBe('1 rebanada')
        ->and(Quantity::of('2.2', Unit::DeliSlice)->roundedUpForPurchase()->format())->toBe('3 lonchas')
        ->and(Unit::Can->group())->not->toBe(Unit::Jar->group())
        ->and(Unit::Slice->group())->not->toBe(Unit::DeliSlice->group());
});

function readableSum(string $a, Unit $ua, string $b, Unit $ub): string
{
    return Quantity::of($a, $ua)->plus(Quantity::of($b, $ub))->readable();
}

test('readable totals: the examples agreed with the user', function () {
    expect(readableSum('400', Unit::Gram, '600', Unit::Gram))->toBe('1 kg')
        ->and(readableSum('750', Unit::Gram, '500', Unit::Gram))->toBe('1,25 kg')
        ->and(readableSum('1500', Unit::Millilitre, '500', Unit::Millilitre))->toBe('2 l')
        ->and(readableSum('250', Unit::Gram, '250', Unit::Gram))->toBe('500 g')
        ->and(readableSum('3', Unit::Piece, '2', Unit::Piece))->toBe('5 unidades');
});

test('readable thresholds: kg and l from 1000 g and 1000 ml, g and ml below', function () {
    expect(Quantity::of('999.999', Unit::Gram)->readable())->toBe('999,999 g')
        ->and(Quantity::of('1000', Unit::Gram)->readable())->toBe('1 kg')
        ->and(Quantity::of('999', Unit::Millilitre)->readable())->toBe('999 ml')
        ->and(Quantity::of('1000', Unit::Millilitre)->readable())->toBe('1 l')
        // Below one kilo or one litre, the small unit, also when stored in the large one.
        ->and(Quantity::of('0.5', Unit::Kilogram)->readable())->toBe('500 g')
        ->and(Quantity::of('0.25', Unit::Litre)->readable())->toBe('250 ml')
        ->and(Quantity::of('0.001', Unit::Kilogram)->readable())->toBe('1 g');
});

test('readable is exact: no rounding, no superfluous zeros, Spanish decimal comma', function () {
    expect(Quantity::of('1234.567', Unit::Gram)->readable())->toBe('1,234567 kg')
        ->and(Quantity::of('1500.5', Unit::Millilitre)->readable())->toBe('1,5005 l')
        ->and(Quantity::of('2', Unit::Kilogram)->readable())->toBe('2 kg')
        ->and(Quantity::of('0.125', Unit::Gram)->readable())->toBe('0,125 g');
});

test('readable never converts indivisible units, and never changes the stored quantity', function () {
    expect(Quantity::of('1.5', Unit::Jar)->readable())->toBe('1,5 botes')
        ->and(Quantity::of('1', Unit::Can)->readable())->toBe('1 lata')
        ->and(Quantity::of('1000', Unit::Slice)->readable())->toBe('1000 rebanadas');

    $stored = Quantity::of('1500', Unit::Gram);
    $stored->readable();
    expect($stored->amount.' '.$stored->unit->value)->toBe('1500.000 g')
        ->and($stored->format())->toBe('1500 g');
});

test('incompatible units are never combined, also for display', function () {
    expect(fn () => Quantity::of('1', Unit::Jar)->plus(Quantity::of('200', Unit::Gram)))
        ->toThrow(IncompatibleUnitsException::class);
});
