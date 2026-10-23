<?php

use App\Enums\HouseholdRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CA-32. Point 8 (2026-10-22): the name of the real household is shared with every page (header) and
 * follows a rename. Point 6: members see their own role as a label; the server protections
 * of roles do not change.
 */
beforeEach(function () {
    $this->admin = User::factory()->withHousehold()->create();
    $this->household = $this->admin->currentHousehold();
});

test('point 8: every page knows the name of the member household, also after a rename', function () {
    $this->household->update(['name' => 'Casa Pérez']);
    $other = User::factory()->withHousehold()->create();
    $other->currentHousehold()->update(['name' => 'Piso compartido']);

    $this->actingAs($this->admin)->get(route('inventory.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.household.name', 'Casa Pérez'));

    $this->patch(route('household.update'), ['name' => 'Casa Pérez y García'])->assertSessionHasNoErrors();

    $this->get(route('menu.show'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.household.name', 'Casa Pérez y García'));
    $this->actingAs($other)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.household.name', 'Piso compartido'));
});

test('point 6: the own member row is marked, and an admin cannot leave the household without an admin', function () {
    $editor = User::factory()->memberOf($this->household, HouseholdRole::Editor)->create();

    // Members are sorted by name: look them up by id.
    $members = collect($this->actingAs($this->admin)->get(route('household.show'))->viewData('page')['props']['members'])->keyBy('id');
    expect($members[$this->admin->id])->toMatchArray(['is_self' => true, 'role' => 'admin'])
        ->and($members[$editor->id])->toMatchArray(['is_self' => false, 'role' => 'editor']);

    // Server protection unchanged: the only admin cannot lower their own role (RN-38).
    $this->patch(route('household.members.update', $this->admin), ['role' => 'editor']);
    expect($this->admin->refresh()->householdRole())->toBe(HouseholdRole::Admin);

    // With another admin it is allowed by the server, as before.
    $this->patch(route('household.members.update', $editor), ['role' => 'admin']);
    $this->patch(route('household.members.update', $this->admin), ['role' => 'editor']);
    expect($this->admin->refresh()->householdRole())->toBe(HouseholdRole::Editor);
});
