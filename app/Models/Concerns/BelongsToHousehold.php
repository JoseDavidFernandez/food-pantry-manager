<?php

namespace App\Models\Concerns;

use App\Models\Household;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Domain models owned by a household (RN-01, RN-02). Authorization is enforced by
 * App\Policies\HouseholdOwnedPolicy; listings must start from the user's household.
 *
 * @property int $household_id
 */
trait BelongsToHousehold
{
    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
