<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Households\HouseholdController;
use App\Http\Controllers\Households\HouseholdSetupController;
use App\Http\Controllers\Households\InvitationController;
use App\Http\Controllers\IngredientAliasController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\IngredientImageController;
use App\Http\Controllers\IngredientRestockController;
use App\Http\Controllers\IngredientUnitsController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\ReceptionController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ShoppingListController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Without a household: create one or accept an invitation (RN-01, RN-39).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('household/setup', [HouseholdSetupController::class, 'show'])->name('household.setup');
    Route::post('household', [HouseholdSetupController::class, 'store'])->middleware('throttle:10,1')->name('household.store');
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('invitations.show');
    Route::post('invitations/{invitation}/accept', [InvitationController::class, 'accept'])->whereNumber('invitation')->middleware('throttle:10,1')->name('invitations.accept');
    Route::post('invitations/{invitation}/decline', [InvitationController::class, 'decline'])->whereNumber('invitation')->middleware('throttle:10,1')->name('invitations.decline');
});

// The household page: members and roles for everyone; admin actions check `household.manage`.
Route::middleware(['auth', 'verified', 'household'])->group(function () {
    Route::get('settings/household', [HouseholdController::class, 'show'])->name('household.show');
    Route::patch('settings/household', [HouseholdController::class, 'update'])->name('household.update');
    Route::post('settings/household/invitations', [HouseholdController::class, 'invite'])->middleware('throttle:20,1')->name('household.invitations.store');
    Route::delete('settings/household/invitations/{invitation}', [HouseholdController::class, 'revoke'])->whereNumber('invitation')->name('household.invitations.destroy');
    Route::patch('settings/household/members/{member}', [HouseholdController::class, 'updateMember'])->whereNumber('member')->name('household.members.update');
    Route::delete('settings/household/members/{member}', [HouseholdController::class, 'removeMember'])->whereNumber('member')->name('household.members.destroy');
    Route::post('settings/household/leave', [HouseholdController::class, 'leave'])->name('household.leave');
});

// Household data (RN-02, RN-38): every member reads; only admins and editors change anything.
Route::middleware(['auth', 'verified', 'household', 'household.write'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('ingredients/images/search', [IngredientImageController::class, 'search'])
        ->middleware(['throttle:20,1', 'can:household.write'])->name('ingredients.images.search');
    Route::resource('ingredients', IngredientController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('ingredients/{ingredient}/image', [IngredientImageController::class, 'show'])->name('ingredients.image.show');
    Route::post('ingredients/{ingredient}/image', [IngredientImageController::class, 'store'])
        ->middleware('throttle:20,1')->name('ingredients.image.store');
    Route::delete('ingredients/{ingredient}/image', [IngredientImageController::class, 'destroy'])->name('ingredients.image.destroy');
    Route::scopeBindings()->group(function () {
        Route::resource('ingredients.aliases', IngredientAliasController::class)->only(['store', 'destroy']);
        Route::resource('ingredients.conversions', IngredientUnitsController::class)->only(['store', 'destroy']);
    });
    Route::put('ingredients/{ingredient}/purchase-unit', [IngredientUnitsController::class, 'purchase'])->name('ingredients.purchase-unit');
    Route::put('ingredients/{ingredient}/minimum', [IngredientRestockController::class, 'minimum'])->name('ingredients.minimum');
    Route::post('ingredients/{ingredient}/depleted', [IngredientRestockController::class, 'depleted'])->name('ingredients.depleted');
    Route::resource('inventory', InventoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::patch('inventory/{inventory}/quantity', [InventoryController::class, 'adjust'])->name('inventory.adjust');

    Route::resource('recipes', RecipeController::class);
    Route::patch('recipes/{recipe}/archive', [RecipeController::class, 'archive'])->name('recipes.archive');
    Route::patch('recipes/{recipe}/restore', [RecipeController::class, 'restore'])->name('recipes.restore');

    Route::get('menu/{week?}', [MenuController::class, 'show'])->where('week', '\d{4}-\d{2}-\d{2}')->name('menu.show');
    // Each lunch and dinner holds several dishes in order (RN-16); a dish is addressed by its id.
    Route::post('menu/{week}/{day}/{meal}/dishes', [MenuController::class, 'store'])
        ->where(['week' => '\d{4}-\d{2}-\d{2}', 'day' => '[1-7]', 'meal' => 'lunch|dinner'])
        ->name('menu.dishes.store');
    Route::prefix('menu/dishes/{dish}')->whereNumber('dish')->name('menu.dishes.')->group(function () {
        Route::put('/', [MenuController::class, 'update'])->name('update');
        Route::delete('/', [MenuController::class, 'destroy'])->name('destroy');
        Route::patch('move', [MenuController::class, 'move'])->name('move');
        Route::patch('position', [MenuController::class, 'position'])->name('position');
        Route::patch('eaten', [MenuController::class, 'eaten'])->name('eaten');
        Route::patch('choice', [MenuController::class, 'choice'])->name('choice');
    });

    // Shopping list of a purchase period (RN-36): it replaced closing the weekly menu.
    Route::get('shopping-list', [ShoppingListController::class, 'period'])->name('shopping-list.period');
    Route::get('shopping-list/{week}', [ShoppingListController::class, 'week'])->where('week', '\d{4}-\d{2}-\d{2}')->name('shopping-list.week');
    Route::get('shopping-lists', [ShoppingListController::class, 'index'])->name('shopping-lists.index');
    Route::prefix('shopping-lists/{list}')->whereNumber(['list', 'item'])->name('shopping-lists.')->group(function () {
        Route::get('/', [ShoppingListController::class, 'show'])->name('show');
        Route::post('items', [ShoppingListController::class, 'store'])->name('items.store');
        Route::patch('items/{item}', [ShoppingListController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [ShoppingListController::class, 'destroy'])->name('items.destroy');
        Route::patch('items/{item}/purchased', [ShoppingListController::class, 'purchased'])->name('items.purchased');
        Route::patch('items/{item}/reviewed', [ShoppingListController::class, 'reviewed'])->name('items.reviewed');
        Route::get('reception', [ReceptionController::class, 'create'])->name('receptions.create');
        Route::post('receptions', [ReceptionController::class, 'store'])->name('receptions.store');
        Route::post('receptions/checked', [ReceptionController::class, 'checked'])->name('receptions.checked');
    });
});

require __DIR__.'/settings.php';
