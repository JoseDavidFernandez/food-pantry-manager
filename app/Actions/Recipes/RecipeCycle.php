<?php

namespace App\Actions\Recipes;

use App\Models\Recipe;
use RuntimeException;

/**
 * A preparation that includes, directly or through others, the recipe it would be added to (RN-43).
 */
class RecipeCycle extends RuntimeException
{
    public function __construct(public readonly Recipe $component)
    {
        parent::__construct("«{$component->name}» ya incluye esta receta: una receta no puede incluirse a sí misma.");
    }
}
