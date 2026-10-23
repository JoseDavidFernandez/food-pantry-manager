<?php

use App\Enums\HouseholdRole;
use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CA-32. Point 7 (2026-10-22): an invitation to someone who already belongs to a household. One
 * household per account (RN-01): nothing is accepted, left or deleted on its own; the conflict
 * is explained and the invitation stays pending.
 */
beforeEach(function () {
    Notification::fake();
    $this->inviter = User::factory()->withHousehold()->create(['name' => 'Ana']);
    $this->target = $this->inviter->currentHousehold();
});

function inviteTo(User $admin, string $email): HouseholdInvitation
{
    test()->actingAs($admin)->post(route('household.invitations.store'), ['email' => $email, 'role' => 'editor'])->assertSessionHasNoErrors();

    return HouseholdInvitation::where('email', $email)->latest('id')->firstOrFail();
}

test('a member of another household sees the conflict and the invitation stays pending', function () {
    $luis = User::factory()->withHousehold(HouseholdRole::Editor)->create(['email' => 'luis@example.com']);
    $own = $luis->currentHousehold();
    User::factory()->memberOf($own, HouseholdRole::Admin)->create();
    $invitation = inviteTo($this->inviter, 'luis@example.com');

    $this->actingAs($luis);
    $this->get(route('household.show'))->assertInertia(fn (Assert $page) => $page
        ->has('receivedInvitations', 1)
        ->where('receivedInvitations.0.household', $this->target->name)
        ->where('leaveBlocker', null));

    $this->post(route('invitations.accept', $invitation))->assertRedirect();
    expect($luis->currentHousehold()->id)->toBe($own->id)
        ->and($invitation->fresh()->status())->toBe(HouseholdInvitation::PENDING)
        ->and($this->target->countMembers())->toBe(1);

    // After leaving explicitly, the same invitation is accepted.
    $this->post(route('household.leave'))->assertRedirect(route('household.setup'));
    $this->post(route('invitations.accept', $invitation))->assertRedirect(route('dashboard'));
    expect($luis->currentHousehold()->id)->toBe($this->target->id)
        ->and($own->fresh())->not->toBeNull()
        ->and($own->countMembers())->toBe(1);
});

test('the invitation page explains the conflict: what blocks leaving, and nothing changes', function () {
    $eva = User::factory()->withHousehold()->create(['email' => 'eva@example.com']);
    User::factory()->memberOf($eva->currentHousehold(), HouseholdRole::Editor)->create();
    inviteTo($this->inviter, 'eva@example.com');
    $token = basename((string) data_get(app('session.store')->get(SessionKey::FLASH_DATA, []), 'invitationLink.url'));

    $this->actingAs($eva)->get(route('invitations.show', $token))->assertInertia(fn (Assert $page) => $page
        ->where('invitation.status', 'pending')
        ->where('conflict.household', $eva->currentHousehold()->name)
        ->where('conflict.role', 'Administrador')
        ->where('conflict.can_leave', false)
        ->where('conflict.blocker', 'Eres el único administrador. Nombra antes a otro miembro administrador para poder salir.'));

    // The last admin is protected: leaving is refused.
    $this->post(route('household.leave'))->assertInertiaFlash('toast.type', 'error');
    expect($eva->householdRole())->toBe(HouseholdRole::Admin);
});

test('the only member of an empty household can leave it; the empty household is deleted', function () {
    $nat = User::factory()->withHousehold()->create(['email' => 'nat@example.com']);
    $empty = $nat->currentHousehold();
    $invitation = inviteTo($this->inviter, 'nat@example.com');

    $this->actingAs($nat);
    // Opening the list creates an empty list: it does not count as data.
    $this->get(route('shopping-list.period'))->assertOk();
    $this->get(route('household.show'))->assertInertia(fn (Assert $page) => $page->where('leaveBlocker', null)->where('leaveDeletesEmpty', true));

    $this->post(route('household.leave'))->assertRedirect(route('household.setup'));
    expect(Household::whereKey($empty->id)->exists())->toBeFalse();

    $this->post(route('invitations.accept', $invitation))->assertRedirect(route('dashboard'));
    expect($nat->currentHousehold()->id)->toBe($this->target->id);
});

test('the only member of a household with data cannot leave: it is never deleted', function () {
    $owner = User::factory()->withHousehold()->create();
    $household = $owner->currentHousehold();
    $arroz = Ingredient::factory()->for($household)->named('Arroz')->create();
    InventoryItem::factory()->for($arroz)->of('1', Unit::Kilogram, StorageLocation::Pantry)->create();

    $this->actingAs($owner)->post(route('household.leave'))
        ->assertInertiaFlash('toast.message', 'Eres el único miembro y el hogar tiene datos: no puedes salir de él. Eliminar un hogar con datos no está disponible en esta versión.');

    expect($owner->currentHousehold()->id)->toBe($household->id)
        ->and(InventoryItem::count())->toBe(1);
});

test('declining from the household page keeps the user in their household', function () {
    $luis = User::factory()->withHousehold()->create(['email' => 'luis@example.com']);
    $invitation = inviteTo($this->inviter, 'luis@example.com');

    $this->actingAs($luis)->from(route('household.show'))
        ->post(route('invitations.decline', $invitation))->assertRedirect(route('household.show'));

    expect($invitation->fresh()->status())->toBe(HouseholdInvitation::DECLINED)
        ->and($luis->hasHousehold())->toBeTrue();
});
