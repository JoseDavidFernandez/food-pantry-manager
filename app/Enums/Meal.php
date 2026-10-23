<?php

namespace App\Enums;

/**
 * The two daily slots of the weekly menu (RN-15).
 */
enum Meal: string
{
    case Lunch = 'lunch';
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Lunch => 'Comida',
            self::Dinner => 'Cena',
        };
    }

    /**
     * Internal reference time of the meal in Europe/Madrid (RN-44): until then a planned dish is
     * pending and counts for the shopping list; from then on it counts as eaten for the
     * estimated stock, unless marked «No comido». Nobody has to confirm anything at that time.
     */
    public function referenceTime(): string
    {
        return match ($this) {
            self::Lunch => '15:00',
            self::Dinner => '22:00',
        };
    }

    /**
     * Order of the meals within a day.
     */
    public function order(): int
    {
        return match ($this) {
            self::Lunch => 0,
            self::Dinner => 1,
        };
    }
}
