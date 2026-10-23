<?php

use App\Support\IngredientName;

test('RN-03: normalization ignores case, accents and extra spaces', function () {
    expect(IngredientName::normalize('  Tomate  '))->toBe('tomate')
        ->and(IngredientName::normalize('Atún   en  ACEITE'))->toBe('atun en aceite')
        ->and(IngredientName::normalize('Plátano'))->toBe(IngredientName::normalize('platano'));
});

test('plurals are not singularized automatically', function () {
    expect(IngredientName::normalize('Tomates'))->not->toBe(IngredientName::normalize('Tomate'));
});

test('clean keeps the original casing and accents', function () {
    expect(IngredientName::clean('  Atún   en aceite '))->toBe('Atún en aceite');
});
