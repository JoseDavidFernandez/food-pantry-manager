<?php

namespace App\Enums;

/**
 * A planned dish is either a saved recipe or a one-off dish (RN-16, RN-17).
 */
enum DishType: string
{
    case Recipe = 'recipe';
    case Adhoc = 'adhoc';
}
