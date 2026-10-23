<?php

namespace App\Policies;

use App\Models\Ingredient;
use App\Models\User;

class IngredientPolicy extends HouseholdOwnedPolicy
{
    public function view(User $user, Ingredient $ingredient): bool
    {
        return $this->ownedBy($user, $ingredient->household_id);
    }

    public function update(User $user, Ingredient $ingredient): bool
    {
        return $this->ownedBy($user, $ingredient->household_id);
    }

    public function delete(User $user, Ingredient $ingredient): bool
    {
        return $this->ownedBy($user, $ingredient->household_id);
    }
}
