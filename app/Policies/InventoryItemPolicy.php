<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy extends HouseholdOwnedPolicy
{
    public function view(User $user, InventoryItem $item): bool
    {
        return $this->ownedBy($user, $item->household_id);
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return $this->ownedBy($user, $item->household_id);
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        return $this->ownedBy($user, $item->household_id);
    }
}
