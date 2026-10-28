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

    /**
     * A normalized name and its possible singular forms, only at the end of the name (tomates →
     * tomate, limones → limon; «cremas de verduras» keeps «cremas»). Used to compare with the
     * category dictionary (RN-31, RN-49), never to merge ingredients (RN-04).
     *
     * @return list<string>
     */
    public static function withSingulars(string $normalized): array
    {
        $candidates = [$normalized];

        if (str_ends_with($normalized, 'es')) {
            $candidates[] = substr($normalized, 0, -2);
        }

        if (str_ends_with($normalized, 's')) {
            $candidates[] = substr($normalized, 0, -1);
        }

        return $candidates;
    }
}
