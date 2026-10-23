<?php

namespace App\Enums;

/**
 * A closed menu is read-only (RN-19). Closing is implemented with the shopping list.
 */
enum MenuStatus: string
{
    case Draft = 'draft';
    case Closed = 'closed';
}
