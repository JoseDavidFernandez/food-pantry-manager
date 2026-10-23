<?php

namespace App\Actions\Ingredients;

use RuntimeException;

/**
 * A change to the units of a food that would make them contradictory (RN-40).
 */
class FoodUnitsRefused extends RuntimeException {}
