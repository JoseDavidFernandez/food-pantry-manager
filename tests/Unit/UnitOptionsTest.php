<?php

use App\Enums\Unit;

/**
 * Unit selectors (2026-10-28): measures, pieces and packs, and three packs no longer offered for
 * new entries that stay valid for existing data.
 */
test('every unit belongs to one section of the selector', function () {
    $kinds = collect(Unit::options())->groupBy('kind')->map(fn ($units) => $units->pluck('value')->all());

    expect($kinds['measure'])->toBe(['g', 'kg', 'ml', 'l'])
        ->and($kinds['piece'])->toBe(['piece', 'slice', 'deli_slice', 'clove', 'head'])
        ->and($kinds['pack'])->toBe(['can', 'jar', 'pack', 'tray', 'bag', 'box', 'net', 'carton']);
});

test('bandeja, caja and malla are not offered but are still valid units', function () {
    $hidden = collect(Unit::options())->where('offered', false)->pluck('value')->all();

    expect($hidden)->toBe(['tray', 'box', 'net'])
        ->and(Unit::tryFrom('tray'))->toBe(Unit::Tray)
        ->and(Unit::Net->label('1'))->toBe('malla');
});
