<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A purchase received from a shopping list (RN-29). Created already confirmed, in the same
 * transaction that updates the stock (RN-30). A list may have several partial receptions.
 *
 * @property int $id
 * @property int $household_id
 * @property int $shopping_list_id
 * @property string $token
 * @property Carbon $confirmed_at
 */
#[Fillable(['household_id', 'shopping_list_id', 'token', 'confirmed_at'])]
class Reception extends Model
{
    use BelongsToHousehold;

    /**
     * @return BelongsTo<ShoppingList, $this>
     */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /**
     * @return HasMany<ReceptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReceptionItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }
}
