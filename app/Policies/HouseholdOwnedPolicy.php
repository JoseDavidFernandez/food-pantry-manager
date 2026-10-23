<?php

namespace App\Policies;

use App\Models\User;

/**
 * Base policy for household-owned resources (RN-02, CA-02): only members of the
 * owning household may view, update or delete them.
 */
abstract class HouseholdOwnedPolicy
{
    protected function ownedBy(User $user, int $householdId): bool
    {
        return $user->belongsToHousehold($householdId);
    }
}
