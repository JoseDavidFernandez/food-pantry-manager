<?php

use App\Enums\HouseholdRole;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CA-34, RN-38 (2026-10-22): deleting an account never leaves the data of a household
 * unreachable, and never deletes a household with data. An empty household left without
 * members goes with the account.
 */
function deleteAccount(User $user): mixed
{
    return test()->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);
}

test('the only member of a household with data cannot delete the account; nothing changes', function () {
    $user = User::factory()->withHousehold()->create();
    $household = $user->currentHousehold();
    $sal = Ingredient::factory()->for($household)->named('Sal')->create();
    InventoryItem::factory()->for($sal)->of('1', Unit::Pack, StorageLocation::Pantry)->create();

    // Said before trying, on the settings page.
    $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('deletionBlocker', fn (string $message) => str_contains($message, 'nadie podría volver a acceder')));

    deleteAccount($user)->assertInertiaFlash('toast.type', 'error');

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->currentHousehold()->is($household))->toBeTrue()
        ->and(Ingredient::where('household_id', $household->id)->count())->toBe(1)
        ->and(InventoryItem::count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('the only member of an empty household deletes the account and the household with it', function () {
    $user = User::factory()->withHousehold()->create();
    $household = $user->currentHousehold();

    $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('deletionBlocker', null));
    deleteAccount($user)->assertRedirect('/');

    expect($user->fresh())->toBeNull()
        ->and(Household::whereKey($household->id)->exists())->toBeFalse();
    $this->assertGuest();
});

test('with other members: an editor leaves the data to them; the last admin must name another first', function () {
    $admin = User::factory()->withHousehold()->create();
    $household = $admin->currentHousehold();
    Ingredient::factory()->for($household)->named('Sal')->create();
    $editor = User::factory()->memberOf($household, HouseholdRole::Editor)->create();
    $other = User::factory()->memberOf($household, HouseholdRole::Editor)->create();

    // The last admin with other members is protected, as before.
    deleteAccount($admin)->assertInertiaFlash('toast.type', 'error');
    expect($admin->fresh())->not->toBeNull();

    // An editor can go: the household and its data stay with the others.
    deleteAccount($editor)->assertRedirect('/');
    expect($editor->fresh())->toBeNull()
        ->and($household->fresh()->countMembers())->toBe(2)
        ->and(Ingredient::where('household_id', $household->id)->count())->toBe(1);

    // Once another member is admin, the first admin can go too; ownership and access stay.
    $this->actingAs($admin)->patch(route('household.members.update', $other), ['role' => 'admin']);
    deleteAccount($admin)->assertRedirect('/');
    expect($admin->fresh())->toBeNull()
        ->and($other->fresh()->householdRole())->toBe(HouseholdRole::Admin)
        ->and(Ingredient::where('household_id', $household->id)->count())->toBe(1);

    // The remaining admin is now the only member of a household with data: blocked.
    deleteAccount($other)->assertInertiaFlash('toast.type', 'error');
    expect($other->fresh())->not->toBeNull();
});
