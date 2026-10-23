<?php

namespace App\Support;

use App\Enums\Unit;
use DomainException;

/**
 * Raised when combining quantities whose units cannot be converted (RN-06).
 */
final class IncompatibleUnitsException extends DomainException
{
    public function __construct(Unit $a, Unit $b)
    {
        parent::__construct("Units [{$a->value}] and [{$b->value}] are not compatible.");
    }
}
