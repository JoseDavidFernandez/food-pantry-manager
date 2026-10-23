<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\QueryException;

function registrationInput(array $overrides = []): array
{
    return [
        'name' => 'Ana López',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        ...$overrides,
    ];
}

test('CA-23, RN-01: registering creates the account without a household and leads to the household setup', function () {
    $this->post(route('register.store'), registrationInput())->assertRedirect();

    $user = User::where('email', 'ana@example.com')->firstOrFail();
    expect($user->households()->count())->toBe(0)
        ->and(Household::count())->toBe(0);

    // Outside development the email is verified first (DT-09); then the household.
    $user->markEmailAsVerified();
    $this->actingAs($user->fresh());

    $this->get(route('dashboard'))->assertRedirect(route('household.setup'));
});

test('CA-23, RN-01: the household is created once, with its creator as admin', function () {
    $user = app(CreateNewUser::class)->create(registrationInput());
    $user->markEmailAsVerified();
    $this->actingAs($user);

    $this->post(route('household.store'), ['name' => 'Casa de Ana'])->assertRedirect(route('dashboard'));
    // A repeated request (refresh, double click, back button) does not create another one.
    $this->post(route('household.store'), ['name' => 'Casa de Ana'])->assertRedirect(route('dashboard'));

    expect(Household::count())->toBe(1)
        ->and($user->householdRole())->toBe(HouseholdRole::Admin)
        ->and($user->currentHousehold()->name)->toBe('Casa de Ana');
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('household.setup'))->assertRedirect(route('dashboard'));
});

test('CA-23, RN-01: an interrupted setup is resumed after logging out and in again', function () {
    $user = app(CreateNewUser::class)->create(registrationInput());
    $user->markEmailAsVerified();

    $this->post(route('login.store'), ['email' => 'ana@example.com', 'password' => 'password']);
    foreach (['dashboard', 'inventory.index', 'recipes.index', 'menu.show', 'shopping-list.period', 'household.show'] as $page) {
        $this->get(route($page))->assertRedirect(route('household.setup'));
    }
    // Writes are refused too, before any data is touched.
    $this->post(route('inventory.store'), ['ingredient' => 'Arroz', 'location' => 'pantry', 'quantity' => '1', 'unit' => 'kg'])
        ->assertRedirect(route('household.setup'));

    $this->get(route('household.setup'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('household/Setup')
        ->where('suggestedName', 'Hogar de Ana López'));
    expect($user->households()->count())->toBe(0);
});

test('existing users with a household log in and go straight to the app', function () {
    $user = User::factory()->withHousehold()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
    $this->get(route('dashboard'))->assertOk();
});

test('a user cannot belong to a second household in V1', function () {
    $user = User::factory()->create();
    Household::factory()->create()->members()->attach($user);

    expect(fn () => Household::factory()->create()->members()->attach($user))
        ->toThrow(QueryException::class);
});

test('deleting a household or a user removes the membership', function () {
    $household = Household::factory()->create();
    $user = User::factory()->create();
    $household->members()->attach($user);

    $user->delete();
    expect($household->members()->count())->toBe(0);

    $other = User::factory()->create();
    $household->members()->attach($other);
    $household->delete();

    expect($other->households()->count())->toBe(0)
        ->and(User::whereKey($other->id)->exists())->toBeTrue();
});
