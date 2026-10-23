<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy extends HouseholdOwnedPolicy
{
    public function view(User $user, Recipe $recipe): bool
    {
        return $this->ownedBy($user, $recipe->household_id);
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->ownedBy($user, $recipe->household_id);
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->ownedBy($user, $recipe->household_id);
    }
}
