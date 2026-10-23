<?php

namespace App\Support;

use Transliterator;

/**
 * Normalization of ingredient names and aliases (RN-03, DATA-MODEL §3):
 * trim, collapse inner spaces, lowercase and strip diacritics. No automatic singularization.
 */
final class IngredientName
{
    public static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    public static function normalize(string $name): string
    {
        $lower = mb_strtolower(self::clean($name));

        /** @var Transliterator $stripMarks */
        $stripMarks = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');

        return (string) $stripMarks->transliterate($lower);
    }
}
