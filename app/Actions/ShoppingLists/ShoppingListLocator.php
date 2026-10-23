<?php

namespace App\Actions\ShoppingLists;

use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Finds lists and lines only within the user's household (RN-02): anything else is a 404.
 */
class ShoppingListLocator
{
    /**
     * @throws NotFoundHttpException
     */
    public function list(Household $household, int $listId): ShoppingList
    {
        return $household->shoppingLists()->whereKey($listId)->first() ?? throw new NotFoundHttpException;
    }

    /**
     * @throws NotFoundHttpException
     */
    public function item(Household $household, int $listId, int $itemId): ShoppingListItem
    {
        return $this->list($household, $listId)->items()->whereKey($itemId)->first() ?? throw new NotFoundHttpException;
    }
}
